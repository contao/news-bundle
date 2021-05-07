<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Contao\Model\Collection;

/**
 * Parent class for news modules.
 *
 * @property string $news_template
 * @property mixed  $news_metaFields
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
abstract class ModuleNews extends Module
{
	/**
	 * Sort out protected archives
	 *
	 * @param array $arrArchives
	 *
	 * @return array
	 */
	protected function sortOutProtected($arrArchives)
	{
		if (empty($arrArchives) || !\is_array($arrArchives))
		{
			return $arrArchives;
		}

		$this->import(FrontendUser::class, 'User');
		$objArchive = NewsArchiveModel::findMultipleByIds($arrArchives);
		$arrArchives = array();

		if ($objArchive !== null)
		{
			$blnFeUserLoggedIn = System::getContainer()->get('contao.security.token_checker')->hasFrontendUser();

			while ($objArchive->next())
			{
				if ($objArchive->protected)
				{
					if (!$blnFeUserLoggedIn || !\is_array($this->User->groups))
					{
						continue;
					}

					$groups = StringUtil::deserialize($objArchive->groups);

					if (empty($groups) || !\is_array($groups) || !\count(array_intersect($groups, $this->User->groups)))
					{
						continue;
					}
				}

				$arrArchives[] = $objArchive->id;
			}
		}

		return $arrArchives;
	}

	/**
	 * Parse an item and return it as string
	 *
	 * @param NewsModel $objArticle
	 * @param boolean   $blnAddArchive
	 * @param string    $strClass
	 * @param integer   $intCount
	 *
	 * @return string
	 */
	protected function parseArticle($objArticle, $blnAddArchive=false, $strClass='', $intCount=0)
	{
		$objTemplate = new FrontendTemplate($this->news_template ?: 'news_latest');
		$objTemplate->setData($objArticle->row());

		if ($objArticle->cssClass)
		{
			$strClass = ' ' . $objArticle->cssClass . $strClass;
		}

		if ($objArticle->featured)
		{
			$strClass = ' featured' . $strClass;
		}

		$objTemplate->class = $strClass;
		$objTemplate->newsHeadline = $objArticle->headline;
		$objTemplate->subHeadline = $objArticle->subheadline;
		$objTemplate->hasSubHeadline = $objArticle->subheadline ? true : false;
		$objTemplate->linkHeadline = $this->generateLink($objArticle->headline, $objArticle, $blnAddArchive);
		$objTemplate->more = $this->generateLink($GLOBALS['TL_LANG']['MSC']['more'], $objArticle, $blnAddArchive, true);
		$objTemplate->link = News::generateNewsUrl($objArticle, $blnAddArchive);
		$objTemplate->archive = $objArticle->getRelated('pid');
		$objTemplate->count = $intCount; // see #5708
		$objTemplate->text = '';
		$objTemplate->hasText = false;
		$objTemplate->hasTeaser = false;

		// Clean the RTE output
		if ($objArticle->teaser)
		{
			$objTemplate->hasTeaser = true;
			$objTemplate->teaser = StringUtil::toHtml5($objArticle->teaser);
			$objTemplate->teaser = StringUtil::encodeEmail($objTemplate->teaser);
		}

		// Display the "read more" button for external/article links
		if ($objArticle->source != 'default')
		{
			$objTemplate->text = true;
			$objTemplate->hasText = true;
		}

		// Compile the news text
		else
		{
			$id = $objArticle->id;

			$objTemplate->text = function () use ($id)
			{
				$strText = '';
				$objElement = ContentModel::findPublishedByPidAndTable($id, 'tl_news');

				if ($objElement !== null)
				{
					while ($objElement->next())
					{
						$strText .= $this->getContentElement($objElement->current());
					}
				}

				return $strText;
			};

			$objTemplate->hasText = static function () use ($objArticle)
			{
				return ContentModel::countPublishedByPidAndTable($objArticle->id, 'tl_news') > 0;
			};
		}

		$arrMeta = $this->getMetaFields($objArticle);

		// Add the meta information
		$objTemplate->date = $arrMeta['date'];
		$objTemplate->hasMetaFields = !empty($arrMeta);
		$objTemplate->numberOfComments = $arrMeta['ccount'];
		$objTemplate->commentCount = $arrMeta['comments'];
		$objTemplate->timestamp = $objArticle->date;
		$objTemplate->author = $arrMeta['author'];
		$objTemplate->datetime = date('Y-m-d\TH:i:sP', $objArticle->date);

		$objTemplate->addImage = false;

		// Add an image
		if ($objArticle->addImage && $objArticle->singleSRC)
		{
			$objModel = FilesModel::findByUuid($objArticle->singleSRC);

			if ($objModel !== null && is_file(System::getContainer()->getParameter('kernel.project_dir') . '/' . $objModel->path))
			{
				// Do not override the field now that we have a model registry (see #6303)
				$arrArticle = $objArticle->row();

				// Override the default image size
				if ($this->imgSize)
				{
					$size = StringUtil::deserialize($this->imgSize);

					if ($size[0] > 0 || $size[1] > 0 || is_numeric($size[2]) || ($size[2][0] ?? null) === '_')
					{
						$arrArticle['size'] = $this->imgSize;
					}
				}

				$arrArticle['singleSRC'] = $objModel->path;
				$this->addImageToTemplate($objTemplate, $arrArticle, null, null, $objModel);

				// Link to the news article if no image link has been defined (see #30)
				if (!$objTemplate->fullsize && !$objTemplate->imageUrl)
				{
					// Unset the image title attribute
					$picture = $objTemplate->picture;
					unset($picture['title']);
					$objTemplate->picture = $picture;

					// Link to the news article
					$objTemplate->href = $objTemplate->link;
					$objTemplate->linkTitle = StringUtil::specialchars(sprintf($GLOBALS['TL_LANG']['MSC']['readMore'], $objArticle->headline), true);

					// If the external link is opened in a new window, open the image link in a new window, too (see #210)
					if ($objTemplate->source == 'external' && $objTemplate->target && strpos($objTemplate->attributes, 'target="_blank"') === false)
					{
						$objTemplate->attributes .= ' target="_blank"';
					}
				}
			}
		}

		$objTemplate->enclosure = array();

		// Add enclosures
		if ($objArticle->addEnclosure)
		{
			$this->addEnclosuresToTemplate($objTemplate, $objArticle->row());
		}

		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['parseArticles']) && \is_array($GLOBALS['TL_HOOKS']['parseArticles']))
		{
			foreach ($GLOBALS['TL_HOOKS']['parseArticles'] as $callback)
			{
				$this->import($callback[0]);
				$this->{$callback[0]}->{$callback[1]}($objTemplate, $objArticle->row(), $this);
			}
		}

		// Tag the news (see #2137)
		if (System::getContainer()->has('fos_http_cache.http.symfony_response_tagger'))
		{
			$responseTagger = System::getContainer()->get('fos_http_cache.http.symfony_response_tagger');
			$responseTagger->addTags(array('contao.db.tl_news.' . $objArticle->id));
		}

		return $objTemplate->parse();
	}

	/**
	 * Parse one or more items and return them as array
	 *
	 * @param Collection $objArticles
	 * @param boolean    $blnAddArchive
	 *
	 * @return array
	 */
	protected function parseArticles($objArticles, $blnAddArchive=false)
	{
		$limit = $objArticles->count();

		if ($limit < 1)
		{
			return array();
		}

		$count = 0;
		$arrArticles = array();
		$uuids = array();

		foreach ($objArticles as $objArticle)
		{
			if ($objArticle->addImage && $objArticle->singleSRC)
			{
				$uuids[] = $objArticle->singleSRC;
			}
		}

		// Preload all images in one query so they are loaded into the model registry
		FilesModel::findMultipleByUuids($uuids);

		foreach ($objArticles as $objArticle)
		{
			$arrArticles[] = $this->parseArticle($objArticle, $blnAddArchive, ((++$count == 1) ? ' first' : '') . (($count == $limit) ? ' last' : '') . ((($count % 2) == 0) ? ' odd' : ' even'), $count);
		}

		return $arrArticles;
	}

	/**
	 * Return the meta fields of a news article as array
	 *
	 * @param NewsModel $objArticle
	 *
	 * @return array
	 */
	protected function getMetaFields($objArticle)
	{
		$meta = StringUtil::deserialize($this->news_metaFields);

		if (!\is_array($meta))
		{
			return array();
		}

		/** @var PageModel $objPage */
		global $objPage;

		$return = array();

		foreach ($meta as $field)
		{
			switch ($field)
			{
				case 'date':
					$return['date'] = Date::parse($objPage->datimFormat, $objArticle->date);
					break;

				case 'author':
					/** @var UserModel $objAuthor */
					if (($objAuthor = $objArticle->getRelated('author')) instanceof UserModel)
					{
						$return['author'] = $GLOBALS['TL_LANG']['MSC']['by'] . ' <span itemprop="author">' . $objAuthor->name . '</span>';
					}
					break;

				case 'comments':
					if ($objArticle->noComments || $objArticle->source != 'default')
					{
						break;
					}

					$bundles = System::getContainer()->getParameter('kernel.bundles');

					if (!isset($bundles['ContaoCommentsBundle']))
					{
						break;
					}

					$intTotal = CommentsModel::countPublishedBySourceAndParent('tl_news', $objArticle->id);
					$return['ccount'] = $intTotal;
					$return['comments'] = sprintf($GLOBALS['TL_LANG']['MSC']['commentCount'], $intTotal);
					break;
			}
		}

		return $return;
	}

	/**
	 * Generate a URL and return it as string
	 *
	 * @param NewsModel $objItem
	 * @param boolean   $blnAddArchive
	 *
	 * @return string
	 *
	 * @deprecated Deprecated since Contao 4.1, to be removed in Contao 5.
	 *             Use News::generateNewsUrl() instead.
	 */
	protected function generateNewsUrl($objItem, $blnAddArchive=false)
	{
		@trigger_error('Using ModuleNews::generateNewsUrl() has been deprecated and will no longer work in Contao 5.0. Use News::generateNewsUrl() instead.', E_USER_DEPRECATED);

		return News::generateNewsUrl($objItem, $blnAddArchive);
	}

	/**
	 * Generate a link and return it as string
	 *
	 * @param string    $strLink
	 * @param NewsModel $objArticle
	 * @param boolean   $blnAddArchive
	 * @param boolean   $blnIsReadMore
	 *
	 * @return string
	 */
	protected function generateLink($strLink, $objArticle, $blnAddArchive=false, $blnIsReadMore=false)
	{
		$blnIsInternal = $objArticle->source != 'external';
		$strReadMore = $blnIsInternal ? $GLOBALS['TL_LANG']['MSC']['readMore'] : $GLOBALS['TL_LANG']['MSC']['open'];
		$strArticleUrl = News::generateNewsUrl($objArticle, $blnAddArchive);

		return sprintf(
			'<a href="%s" title="%s"%s itemprop="url">%s%s</a>',
			$strArticleUrl,
			StringUtil::specialchars(sprintf($strReadMore, $blnIsInternal ? $objArticle->headline : $strArticleUrl), true),
			($objArticle->target && !$blnIsInternal ? ' target="_blank" rel="noreferrer noopener"' : ''),
			($blnIsReadMore ? $strLink : '<span itemprop="headline">' . $strLink . '</span>'),
			($blnIsReadMore && $blnIsInternal ? '<span class="invisible"> ' . $objArticle->headline . '</span>' : '')
		);
	}
}

class_alias(ModuleNews::class, 'ModuleNews');
