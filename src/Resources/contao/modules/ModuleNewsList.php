<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Patchwork\Utf8;

/**
 * Front end module "news list".
 *
 * @property array  $news_archives
 * @property string $news_featured
 * @property string $news_order
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class ModuleNewsList extends ModuleNews
{

	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'mod_newslist';

	/**
	 * Display a wildcard in the back end
	 *
	 * @return string
	 */
	public function generate()
	{
		if (TL_MODE == 'BE')
		{
			/** @var BackendTemplate|object $objTemplate */
			$objTemplate = new \BackendTemplate('be_wildcard');

			$objTemplate->wildcard = '### ' . Utf8::strtoupper($GLOBALS['TL_LANG']['FMD']['newslist'][0]) . ' ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		$this->news_archives = $this->sortOutProtected(\StringUtil::deserialize($this->news_archives));

		// Return if there are no archives
		if (empty($this->news_archives) || !\is_array($this->news_archives))
		{
			return '';
		}

		// Show the news reader if an item has been selected
		if ($this->news_readerModule > 0 && (isset($_GET['items']) || (\Config::get('useAutoItem') && isset($_GET['auto_item']))))
		{
			return $this->getFrontendModule($this->news_readerModule, $this->strColumn);
		}

		return parent::generate();
	}

	/**
	 * Generate the module
	 */
	protected function compile()
	{
		$limit = null;
		$offset = (int) $this->skipFirst;

		// Maximum number of items
		if ($this->numberOfItems > 0)
		{
			$limit = $this->numberOfItems;
		}

		// Handle featured news
		if ($this->news_featured == 'featured')
		{
			$blnFeatured = true;
		}
		elseif ($this->news_featured == 'unfeatured')
		{
			$blnFeatured = false;
		}
		else
		{
			$blnFeatured = null;
		}

		$this->Template->articles = array();
		$this->Template->empty = $GLOBALS['TL_LANG']['MSC']['emptyList'];

		// Get the total number of items
		$intTotal = $this->countItems($this->news_archives, $blnFeatured);

		if ($intTotal < 1)
		{
			return;
		}

		$total = $intTotal - $offset;

		// Split the results
		if ($this->perPage > 0 && (!isset($limit) || $this->numberOfItems > $this->perPage))
		{
			// Adjust the overall limit
			if (isset($limit))
			{
				$total = min($limit, $total);
			}

			// Get the current page
			$id = 'page_n' . $this->id;
			$page = (\Input::get($id) !== null) ? \Input::get($id) : 1;

			// Do not index or cache the page if the page number is outside the range
			if ($page < 1 || $page > max(ceil($total/$this->perPage), 1))
			{
				throw new PageNotFoundException('Page not found: ' . \Environment::get('uri'));
			}

			// Set limit and offset
			$limit = $this->perPage;
			$offset += (max($page, 1) - 1) * $this->perPage;
			$skip = (int) $this->skipFirst;

			// Overall limit
			if ($offset + $limit > $total + $skip)
			{
				$limit = $total + $skip - $offset;
			}

			// Add the pagination menu
			$objPagination = new \Pagination($total, $this->perPage, \Config::get('maxPaginationLinks'), $id);
			$this->Template->pagination = $objPagination->generate("\n  ");
		}

		$objArticles = $this->fetchItems($this->news_archives, $blnFeatured, ($limit ?: 0), $offset);

		// Add the articles
		if ($objArticles !== null)
		{
			$this->Template->articles = $this->parseArticles($objArticles);
		}

		$this->Template->archives = $this->news_archives;
	}

	/**
	 * Count the total matching items
	 *
	 * @param array   $newsArchives
	 * @param boolean $blnFeatured
	 *
	 * @return integer
	 */
	protected function countItems($newsArchives, $blnFeatured)
	{
		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['newsListCountItems']) && \is_array($GLOBALS['TL_HOOKS']['newsListCountItems']))
		{
			foreach ($GLOBALS['TL_HOOKS']['newsListCountItems'] as $callback)
			{
				if (($intResult = \System::importStatic($callback[0])->{$callback[1]}($newsArchives, $blnFeatured, $this)) === false)
				{
					continue;
				}

				if (\is_int($intResult))
				{
					return $intResult;
				}
			}
		}

		return \NewsModel::countPublishedByPids($newsArchives, $blnFeatured);
	}

	/**
	 * Fetch the matching items
	 *
	 * @param array   $newsArchives
	 * @param boolean $blnFeatured
	 * @param integer $limit
	 * @param integer $offset
	 *
	 * @return Model\Collection|NewsModel|null
	 */
	protected function fetchItems($newsArchives, $blnFeatured, $limit, $offset)
	{
		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['newsListFetchItems']) && \is_array($GLOBALS['TL_HOOKS']['newsListFetchItems']))
		{
			foreach ($GLOBALS['TL_HOOKS']['newsListFetchItems'] as $callback)
			{
				if (($objCollection = \System::importStatic($callback[0])->{$callback[1]}($newsArchives, $blnFeatured, $limit, $offset, $this)) === false)
				{
					continue;
				}

				if ($objCollection === null || $objCollection instanceof Model\Collection)
				{
					return $objCollection;
				}
			}
		}

		// Determine sorting
		$t = \NewsModel::getTable();
		$arrOptions = array();

		switch ($this->news_order)
		{
			case 'order_headline_asc':
				$arrOptions['order'] = "$t.headline";
				break;

			case 'order_headline_desc':
				$arrOptions['order'] = "$t.headline DESC";
				break;

			case 'order_random':
				$arrOptions['order'] = "RAND()";
				break;

			case 'order_date_asc':
				$arrOptions['order'] = "$t.date";
				break;

			default:
				$arrOptions['order'] = "$t.date DESC";
		}

		return \NewsModel::findPublishedByPids($newsArchives, $blnFeatured, $limit, $offset, $arrOptions);
	}
}

class_alias(ModuleNewsList::class, 'ModuleNewsList');
