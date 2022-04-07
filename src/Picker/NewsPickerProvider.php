<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\NewsBundle\Picker;

use Contao\CoreBundle\Framework\FrameworkAwareInterface;
use Contao\CoreBundle\Framework\FrameworkAwareTrait;
use Contao\CoreBundle\Picker\AbstractInsertTagPickerProvider;
use Contao\CoreBundle\Picker\DcaPickerProviderInterface;
use Contao\CoreBundle\Picker\PickerConfig;
use Contao\NewsArchiveModel;
use Contao\NewsModel;
use Knp\Menu\FactoryInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

class NewsPickerProvider extends AbstractInsertTagPickerProvider implements DcaPickerProviderInterface, FrameworkAwareInterface
{
    use FrameworkAwareTrait;

    /**
     * @internal Do not inherit from this class; decorate the "contao_news.picker.news_provider" service instead
     */
    public function __construct(FactoryInterface $menuFactory, RouterInterface $router, ?TranslatorInterface $translator, private Security $security)
    {
        parent::__construct($menuFactory, $router, $translator);
    }

    public function getName(): string
    {
        return 'newsPicker';
    }

    public function supportsContext($context): bool
    {
        return 'link' === $context && $this->security->isGranted('contao_user.modules', 'news');
    }

    public function supportsValue(PickerConfig $config): bool
    {
        return $this->isMatchingInsertTag($config);
    }

    public function getDcaTable(PickerConfig $config = null): string
    {
        return 'tl_news';
    }

    public function getDcaAttributes(PickerConfig $config): array
    {
        $attributes = ['fieldType' => 'radio'];

        if ($source = $config->getExtra('source')) {
            $attributes['preserveRecord'] = $source;
        }

        if ($this->supportsValue($config)) {
            $attributes['value'] = $this->getInsertTagValue($config);

            if ($flags = $this->getInsertTagFlags($config)) {
                $attributes['flags'] = $flags;
            }
        }

        return $attributes;
    }

    public function convertDcaValue(PickerConfig $config, $value): string
    {
        return sprintf($this->getInsertTag($config), $value);
    }

    protected function getRouteParameters(PickerConfig $config = null): array
    {
        $params = ['do' => 'news'];

        if (null === $config || !$config->getValue() || !$this->supportsValue($config)) {
            return $params;
        }

        if (null !== ($newsArchiveId = $this->getNewsArchiveId($this->getInsertTagValue($config)))) {
            $params['table'] = 'tl_news';
            $params['id'] = $newsArchiveId;
        }

        return $params;
    }

    protected function getDefaultInsertTag(): string
    {
        return '{{news_url::%s}}';
    }

    private function getNewsArchiveId(int|string $id): ?int
    {
        $newsAdapter = $this->framework->getAdapter(NewsModel::class);

        if (!($newsModel = $newsAdapter->findById($id)) instanceof NewsModel) {
            return null;
        }

        if (!($newsArchive = $newsModel->getRelated('pid')) instanceof NewsArchiveModel) {
            return null;
        }

        return (int) $newsArchive->id;
    }
}
