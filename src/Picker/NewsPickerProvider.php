<?php

/*
 * This file is part of Contao.
 *
 * Copyright (c) 2005-2017 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Contao\NewsBundle\Picker;

use Contao\CoreBundle\Framework\FrameworkAwareInterface;
use Contao\CoreBundle\Framework\FrameworkAwareTrait;
use Contao\CoreBundle\Menu\AbstractMenuProvider;
use Contao\CoreBundle\Menu\PickerMenuProviderInterface;
use Contao\CoreBundle\Picker\AbstractPickerProvider;
use Contao\CoreBundle\Picker\PickerConfig;
use Contao\DataContainer;
use Contao\NewsArchiveModel;
use Contao\NewsModel;
use Knp\Menu\FactoryInterface;
use Knp\Menu\ItemInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the news picker.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class NewsPickerProvider extends AbstractPickerProvider implements FrameworkAwareInterface
{
    use FrameworkAwareTrait;

    /**
     * {@inheritdoc}
     */
    public function getAlias()
    {
        return 'newsPicker';
    }

    /**
     * {@inheritdoc}
     */
    protected function getLinkClass()
    {
        return 'news';
    }

    /**
     * {@inheritdoc}
     */
    public function supportsContext($context)
    {
        return 'link' === $context && $this->getUser()->hasAccess('news', 'modules');
    }

    /**
     * {@inheritdoc}
     */
    public function supportsValue(PickerConfig $config)
    {
        return false !== strpos($config->getValue(), '{{news_url::');
    }

    /**
     * {@inheritdoc}
     */
    protected function getRouteParameters(PickerConfig $config)
    {
        $params = [
            'do' => 'news',
        ];

        if ($config->getValue() && false !== strpos($config->getValue(), '{{news_url::')) {
            $value = str_replace(['{{news_url::', '}}'], '', $config->getValue());

            if (null !== ($newsArchiveId = $this->getNewsArchiveId($value))) {
                $params['table'] = 'tl_news';
                $params['id'] = $newsArchiveId;
            }
        }

        return $params;
    }

    /**
     * {@inheritdoc}
     */
    public function prepareConfig(PickerConfig $config, DataContainer $dc)
    {
        if ('tl_news' !== $dc->table) {
            return null;
        }

        $result = ['fieldType' => $config->getExtra('fieldType')];

        if ('link' === $config->getContext() && $this->supportsValue($config)) {
            $result['value'] = str_replace(['{{news_url::', '}}'], '', $config->getValue());
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function prepareValue(PickerConfig $config, $value)
    {
        return '{{news_url::'.$value.'}}';
    }

    /**
     * Returns the news archive ID.
     *
     * @param int $id
     *
     * @return int|null
     */
    private function getNewsArchiveId($id)
    {
        /** @var NewsModel $newsAdapter */
        $newsAdapter = $this->framework->getAdapter(NewsModel::class);

        if (!(($newsModel = $newsAdapter->findById($id)) instanceof NewsModel)) {
            return null;
        }

        if (!(($newsArchive = $newsModel->getRelated('pid')) instanceof NewsArchiveModel)) {
            return null;
        }

        return $newsArchive->id;
    }
}
