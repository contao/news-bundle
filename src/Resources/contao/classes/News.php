<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

/**
 * Provide methods regarding news archives.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class News extends Frontend
{
	/**
	 * URL cache array
	 * @var array
	 */
	private static $arrUrlCache = array();

	/**
	 * Page cache array
	 * @var array
	 */
	private static $arrPageCache = array();

	/**
	 * Update a particular RSS feed
	 *
	 * @param integer $intId
	 */
	public function generateFeed($intId)
	{
		$objFeed = NewsFeedModel::findByPk($intId);

		if ($objFeed === null)
		{
			return;
		}

		$objFeed->feedName = $objFeed->alias ?: 'news' . $objFeed->id;

		// Delete XML file
		if (Input::get('act') == 'delete')
		{
			$this->import(Files::class, 'Files');
			$this->Files->delete($objFeed->feedName . '.xml');
		}

		// Update XML file
		else
		{
			$this->generateFiles($objFeed->row());
			$this->log('Generated news feed "' . $objFeed->feedName . '.xml"', __METHOD__, TL_CRON);
		}
	}

	/**
	 * Delete old files and generate all feeds
	 */
	public function generateFeeds()
	{
		$this->import(Automator::class, 'Automator');
		$this->Automator->purgeXmlFiles();

		$objFeed = NewsFeedModel::findAll();

		if ($objFeed !== null)
		{
			while ($objFeed->next())
			{
				$objFeed->feedName = $objFeed->alias ?: 'news' . $objFeed->id;
				$this->generateFiles($objFeed->row());
				$this->log('Generated news feed "' . $objFeed->feedName . '.xml"', __METHOD__, TL_CRON);
			}
		}
	}

	/**
	 * Generate all feeds including a certain archive
	 * #
	 * @param integer $intId
	 */
	public function generateFeedsByArchive($intId)
	{
		$objFeed = NewsFeedModel::findByArchive($intId);

		if ($objFeed !== null)
		{
			while ($objFeed->next())
			{
				$objFeed->feedName = $objFeed->alias ?: 'news' . $objFeed->id;

				// Update the XML file
				$this->generateFiles($objFeed->row());
				$this->log('Generated news feed "' . $objFeed->feedName . '.xml"', __METHOD__, TL_CRON);
			}
		}
	}

	/**
	 * Generate an XML files and save them to the root directory
	 *
	 * @param array $arrFeed
	 */
	protected function generateFiles($arrFeed)
	{
		$arrArchives = StringUtil::deserialize($arrFeed['archives']);

		if (empty($arrArchives) || !\is_array($arrArchives))
		{
			return;
		}

		$strType = ($arrFeed['format'] == 'atom') ? 'generateAtom' : 'generateRss';
		$strLink = $arrFeed['feedBase'] ?: Environment::get('base');
		$strFile = $arrFeed['feedName'];

		$objFeed = new Feed($strFile);
		$objFeed->link = $strLink;
		$objFeed->title = $arrFeed['title'];
		$objFeed->description = $arrFeed['description'];
		$objFeed->language = $arrFeed['language'];
		$objFeed->published = $arrFeed['tstamp'];

		// Get the items
		if ($arrFeed['maxItems'] > 0)
		{
			$objArticle = NewsModel::findPublishedByPids($arrArchives, null, $arrFeed['maxItems']);
		}
		else
		{
			$objArticle = NewsModel::findPublishedByPids($arrArchives);
		}

		// Parse the items
		if ($objArticle !== null)
		{
			$arrUrls = array();

			$request = System::getContainer()->get('request_stack')->getCurrentRequest();

			if ($request)
			{
				$origScope = $request->attributes->get('_scope');
				$request->attributes->set('_scope', 'frontend');
			}

			$origObjPage = $GLOBALS['objPage'] ?? null;

			while ($objArticle->next())
			{
				$jumpTo = $objArticle->getRelated('pid')->jumpTo;

				// No jumpTo page set (see #4784)
				if (!$jumpTo)
				{
					continue;
				}

				$objParent = $this->getPageWithDetails($jumpTo);

				// A jumpTo page is set but does no longer exist (see #5781)
				if ($objParent === null)
				{
					continue;
				}

				// Override the global page object (#2946)
				$GLOBALS['objPage'] = $objParent;

				// Get the jumpTo URL
				if (!isset($arrUrls[$jumpTo]))
				{
					$arrUrls[$jumpTo] = $objParent->getAbsoluteUrl(Config::get('useAutoItem') ? '/%s' : '/items/%s');
				}

				$strUrl = $arrUrls[$jumpTo];

				$objItem = new FeedItem();
				$objItem->title = $objArticle->headline;
				$objItem->link = $this->getLink($objArticle, $strUrl);
				$objItem->published = $objArticle->date;

				/** @var UserModel $objAuthor */
				if (($objAuthor = $objArticle->getRelated('author')) instanceof UserModel)
				{
					$objItem->author = $objAuthor->name;
				}

				// Prepare the description
				if ($arrFeed['source'] == 'source_text')
				{
					$strDescription = '';
					$objElement = ContentModel::findPublishedByPidAndTable($objArticle->id, 'tl_news');

					if ($objElement !== null)
					{
						// Overwrite the request (see #7756)
						$strRequest = Environment::get('request');
						Environment::set('request', $objItem->link);

						while ($objElement->next())
						{
							$strDescription .= $this->getContentElement($objElement->current());
						}

						Environment::set('request', $strRequest);
					}
				}
				else
				{
					$strDescription = $objArticle->teaser;
				}

				$strDescription = $this->replaceInsertTags($strDescription, false);
				$objItem->description = $this->convertRelativeUrls($strDescription, $strLink);

				// Add the article image as enclosure
				if ($objArticle->addImage)
				{
					$objFile = FilesModel::findByUuid($objArticle->singleSRC);

					if ($objFile !== null)
					{
						$objItem->addEnclosure($objFile->path, $strLink, 'media:content');
					}
				}

				// Enclosures
				if ($objArticle->addEnclosure)
				{
					$arrEnclosure = StringUtil::deserialize($objArticle->enclosure, true);

					if (\is_array($arrEnclosure))
					{
						$objFile = FilesModel::findMultipleByUuids($arrEnclosure);

						if ($objFile !== null)
						{
							while ($objFile->next())
							{
								$objItem->addEnclosure($objFile->path, $strLink);
							}
						}
					}
				}

				$objFeed->addItem($objItem);
			}

			if ($request)
			{
				$request->attributes->set('_scope', $origScope);
			}

			$GLOBALS['objPage'] = $origObjPage;
		}

		$webDir = StringUtil::stripRootDir(System::getContainer()->getParameter('contao.web_dir'));

		// Create the file
		File::putContent($webDir . '/share/' . $strFile . '.xml', $this->replaceInsertTags($objFeed->$strType(), false));
	}

	/**
	 * Add news items to the indexer
	 *
	 * @param array   $arrPages
	 * @param integer $intRoot
	 * @param boolean $blnIsSitemap
	 *
	 * @return array
	 */
	public function getSearchablePages($arrPages, $intRoot=0, $blnIsSitemap=false)
	{
		$arrRoot = array();

		if ($intRoot > 0)
		{
			$arrRoot = $this->Database->getChildRecords($intRoot, 'tl_page');
		}

		$arrProcessed = array();
		$time = time();

		// Get all news archives
		$objArchive = NewsArchiveModel::findByProtected('');

		// Walk through each archive
		if ($objArchive !== null)
		{
			while ($objArchive->next())
			{
				// Skip news archives without target page
				if (!$objArchive->jumpTo)
				{
					continue;
				}

				// Skip news archives outside the root nodes
				if (!empty($arrRoot) && !\in_array($objArchive->jumpTo, $arrRoot))
				{
					continue;
				}

				// Get the URL of the jumpTo page
				if (!isset($arrProcessed[$objArchive->jumpTo]))
				{
					$objParent = PageModel::findWithDetails($objArchive->jumpTo);

					// The target page does not exist
					if ($objParent === null)
					{
						continue;
					}

					// The target page has not been published (see #5520)
					if (!$objParent->published || ($objParent->start && $objParent->start > $time) || ($objParent->stop && $objParent->stop <= $time))
					{
						continue;
					}

					if ($blnIsSitemap)
					{
						// The target page is protected (see #8416)
						if ($objParent->protected)
						{
							continue;
						}

						// The target page is exempt from the sitemap (see #6418)
						if ($objParent->robots == 'noindex,nofollow')
						{
							continue;
						}
					}

					// Generate the URL
					$arrProcessed[$objArchive->jumpTo] = $objParent->getAbsoluteUrl(Config::get('useAutoItem') ? '/%s' : '/items/%s');
				}

				$strUrl = $arrProcessed[$objArchive->jumpTo];

				// Get the items
				$objArticle = NewsModel::findPublishedDefaultByPid($objArchive->id);

				if ($objArticle !== null)
				{
					while ($objArticle->next())
					{
						if ($blnIsSitemap && $objArticle->robots === 'noindex,nofollow')
						{
							continue;
						}

						$arrPages[] = $this->getLink($objArticle, $strUrl);
					}
				}
			}
		}

		return $arrPages;
	}

	/**
	 * Generate a URL and return it as string
	 *
	 * @param NewsModel $objItem
	 * @param boolean   $blnAddArchive
	 * @param boolean   $blnAbsolute
	 *
	 * @return string
	 */
	public static function generateNewsUrl($objItem, $blnAddArchive=false, $blnAbsolute=false)
	{
		$strCacheKey = 'id_' . $objItem->id . ($blnAbsolute ? '_absolute' : '');

		// Load the URL from cache
		if (isset(self::$arrUrlCache[$strCacheKey]))
		{
			return self::$arrUrlCache[$strCacheKey];
		}

		// Initialize the cache
		self::$arrUrlCache[$strCacheKey] = null;

		switch ($objItem->source)
		{
			// Link to an external page
			case 'external':
				if (0 === strncmp($objItem->url, 'mailto:', 7))
				{
					self::$arrUrlCache[$strCacheKey] = StringUtil::encodeEmail($objItem->url);
				}
				else
				{
					self::$arrUrlCache[$strCacheKey] = StringUtil::ampersand($objItem->url);
				}
				break;

			// Link to an internal page
			case 'internal':
				if (($objTarget = $objItem->getRelated('jumpTo')) instanceof PageModel)
				{
					/** @var PageModel $objTarget */
					self::$arrUrlCache[$strCacheKey] = StringUtil::ampersand($blnAbsolute ? $objTarget->getAbsoluteUrl() : $objTarget->getFrontendUrl());
				}
				break;

			// Link to an article
			case 'article':
				if (($objArticle = ArticleModel::findByPk($objItem->articleId)) instanceof ArticleModel && ($objPid = $objArticle->getRelated('pid')) instanceof PageModel)
				{
					$params = '/articles/' . ($objArticle->alias ?: $objArticle->id);

					/** @var PageModel $objPid */
					self::$arrUrlCache[$strCacheKey] = StringUtil::ampersand($blnAbsolute ? $objPid->getAbsoluteUrl($params) : $objPid->getFrontendUrl($params));
				}
				break;
		}

		// Link to the default page
		if (self::$arrUrlCache[$strCacheKey] === null)
		{
			$objPage = PageModel::findByPk($objItem->getRelated('pid')->jumpTo);

			if (!$objPage instanceof PageModel)
			{
				self::$arrUrlCache[$strCacheKey] = StringUtil::ampersand(Environment::get('request'));
			}
			else
			{
				$params = (Config::get('useAutoItem') ? '/' : '/items/') . ($objItem->alias ?: $objItem->id);

				self::$arrUrlCache[$strCacheKey] = StringUtil::ampersand($blnAbsolute ? $objPage->getAbsoluteUrl($params) : $objPage->getFrontendUrl($params));
			}

			// Add the current archive parameter (news archive)
			if ($blnAddArchive && Input::get('month'))
			{
				self::$arrUrlCache[$strCacheKey] .= '?month=' . Input::get('month');
			}
		}

		return self::$arrUrlCache[$strCacheKey];
	}

	/**
	 * Return the link of a news article
	 *
	 * @param NewsModel $objItem
	 * @param string    $strUrl
	 * @param string    $strBase
	 *
	 * @return string
	 */
	protected function getLink($objItem, $strUrl, $strBase='')
	{
		switch ($objItem->source)
		{
			// Link to an external page
			case 'external':
				return $objItem->url;

			// Link to an internal page
			case 'internal':
				if (($objTarget = $objItem->getRelated('jumpTo')) instanceof PageModel)
				{
					/** @var PageModel $objTarget */
					return $objTarget->getAbsoluteUrl();
				}
				break;

			// Link to an article
			case 'article':
				if (($objArticle = ArticleModel::findByPk($objItem->articleId)) instanceof ArticleModel && ($objPid = $objArticle->getRelated('pid')) instanceof PageModel)
				{
					/** @var PageModel $objPid */
					return StringUtil::ampersand($objPid->getAbsoluteUrl('/articles/' . ($objArticle->alias ?: $objArticle->id)));
				}
				break;
		}

		// Backwards compatibility (see #8329)
		if ($strBase && !preg_match('#^https?://#', $strUrl))
		{
			$strUrl = $strBase . $strUrl;
		}

		// Link to the default page
		return sprintf(preg_replace('/%(?!s)/', '%%', $strUrl), ($objItem->alias ?: $objItem->id));
	}

	/**
	 * Return the names of the existing feeds so they are not removed
	 *
	 * @return array
	 */
	public function purgeOldFeeds()
	{
		$arrFeeds = array();
		$objFeeds = NewsFeedModel::findAll();

		if ($objFeeds !== null)
		{
			while ($objFeeds->next())
			{
				$arrFeeds[] = $objFeeds->alias ?: 'news' . $objFeeds->id;
			}
		}

		return $arrFeeds;
	}

	/**
	 * Return the page object with loaded details for the given page ID
	 *
	 * @param  integer        $intPageId
	 * @return PageModel|null
	 */
	private function getPageWithDetails($intPageId)
	{
		if (!isset(self::$arrPageCache[$intPageId]))
		{
			self::$arrPageCache[$intPageId] = PageModel::findWithDetails($intPageId);
		}

		return self::$arrPageCache[$intPageId];
	}
}

class_alias(News::class, 'News');
