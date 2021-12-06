<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\NewsBundle\EventListener;

use Contao\CoreBundle\Event\PreviewUrlConvertEvent;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\News;
use Contao\NewsModel;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
class PreviewUrlConvertListener
{
    private ContaoFramework $framework;

    public function __construct(ContaoFramework $framework)
    {
        $this->framework = $framework;
    }

    /**
     * Adds the front end preview URL to the event.
     */
    public function __invoke(PreviewUrlConvertEvent $event): void
    {
        if (!$this->framework->isInitialized()) {
            return;
        }

        if (null === ($news = $this->getNewsModel($event->getRequest()))) {
            return;
        }

        $event->setUrl($this->framework->getAdapter(News::class)->generateNewsUrl($news, false, true));
    }

    private function getNewsModel(Request $request): ?NewsModel
    {
        if (!$request->query->has('news')) {
            return null;
        }

        return $this->framework->getAdapter(NewsModel::class)->findByPk($request->query->get('news'));
    }
}
