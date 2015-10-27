<?php

namespace Contao\NewsBundle\EventListener;

use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\NewsModel;

class InsertTagsListener
{
    /**
     * @var ContaoFrameworkInterface
     */
    private $framework;

    /**
     * Constructor.
     *
     * @param ContaoFrameworkInterface $framework
     */
    public function __construct(ContaoFrameworkInterface $framework)
    {
        $this->framework = $framework;
    }

    public function replaceInsertTags($value)
    {
        $elements = explode('::', $value);
        $tag      = strtolower($elements[0]);

        switch ($tag) {
            case 'news':
            case 'news_open':
            case 'news_url':
            case 'news_title':
                return $this->getNewsTag($elements[1], $tag);
                break;

            case 'news_teaser':
                return $this->getNewsTeaser($elements[1]);

            case 'news_feed':
                return $this->getNewsFeed($elements[1]);
        }

        return false;
    }

    private function getNewsTag($id, $tag)
    {
        /** @var \Contao\NewsModel $repository */
        $repository = $this->framework->getAdapter('Contao\NewsModel');
        $news       = $repository->findByIdOrAlias($id);

        if (null === $news) {
            return '';
        }

        switch ($tag) {
            case 'news':
                return sprintf(
                    '<a href="%s" title="%s">%s</a>',
                    $this->getNewsUrl($news),
                    specialchars($news->headline),
                    $news->headline
                );

            case 'news_open':
                return sprintf('<a href="%s" title="%s">', $this->getNewsUrl($news), specialchars($news->headline));

            case 'news_url':
                return $this->getNewsUrl($news);

            case 'news_title':
                return specialchars($news->headline);
        }

        return '';
    }

    private function getNewsTeaser($id)
    {
        /**
         * @var \Contao\NewsModel  $repository
         * @var \Contao\StringUtil $string
         */
        $repository = $this->framework->getAdapter('Contao\NewsModel');
        $string     = $this->framework->getAdapter('Contao\StringUtil');
        $news       = $repository->findByIdOrAlias($id);

        return null !== $news ? $string->toHtml5($news->teaser) : '';
    }

    private function getNewsFeed($id)
    {
        /** @var \Contao\NewsFeedModel $repository */
        $repository = $this->framework->getAdapter('Contao\NewsFeedModel');
        $feed       = $repository->findByPk($id);

        return null !== $feed ? $feed->feedBase . 'share/' . $feed->alias . '.xml' : '';
    }

    private function getNewsUrl(NewsModel $news)
    {
        if ('external' === $news->source) {
            return $news->url;
        }

        if ('internal' === $news->source) {
            return $this->generateInternalNewsUrl($news);
        }

        if ('article' === $news->source) {
            return $this->generateArticleNewsUrl($news);
        }

        /**
         * @var \Contao\Controller       $controller
         * @var \Contao\Config           $config
         * @var \Contao\NewsArchiveModel $archive
         */
        $controller = $this->framework->getAdapter('Contao\Controller');
        $config     = $this->framework->getAdapter('Contao\Config');
        $archive    = $news->getRelated('pid');

        if (null === $archive || null === $archive->getRelated('jumpTo')) {
            return '';
        }

        return $controller->generateFrontendUrl(
            $archive->getRelated('jumpTo')->row(),
            ($config->get('useAutoItem') ?  '/' : '/items/') . ($news->alias ?: $news->id)
        );
    }

    private function generateInternalNewsUrl(NewsModel $news)
    {
        /**
         * @var \Contao\Controller $controller
         * @var \Contao\PageModel  $jumpTo
         */
        $controller = $this->framework->getAdapter('Contao\Controller');
        $jumpTo     = $news->getRelated('jumpTo');

        return null !== $jumpTo ? $controller->generateFrontendUrl($jumpTo->row()) : '';
    }

    private function generateArticleNewsUrl(NewsModel $news)
    {
        /**
         * @var \Contao\Controller   $controller
         * @var \Contao\ArticleModel $repository
         */
        $controller = $this->framework->getAdapter('Contao\Controller');
        $repository = $this->framework->getAdapter('Contao\ArticleModel');
        $article    = $repository->findByPk($news->articleId, array('eager' => true));

        if (null === $article || null === $article->getRelated('pid')) {
            return '';
        }

        return $controller->generateFrontendUrl(
            $article->getRelated('pid')->row(), '/articles/' . ($article->alias ?: $article->id)
        );
    }
}
