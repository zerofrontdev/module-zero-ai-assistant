<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Banner;

use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;
use Zero\Banner\Model\ResourceModel\Banner\Collection as BannerCollection;
use Zero\Banner\Model\ResourceModel\Slide\Collection as SlideCollection;

/**
 * list_banners: the banners (hero sliders) and their slides.
 */
class ListBanners extends ReadTool
{
    /**
     * The object manager, not factories: Zero_Banner is optional, and a
     * generated factory for an absent class breaks setup:di:compile.
     */
    public function __construct(private readonly ObjectManagerInterface $objectManager)
    {
    }

    public function name(): string
    {
        return 'list_banners';
    }

    public function description(): string
    {
        return 'The banners (hero sliders): id, name, identifier, active, and their slides (id, position, active, heading, eyebrow, text, button, link, image, date_from/date_to). Use save_slide to add or change a slide.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function resource(): string
    {
        return 'Zero_Banner::banner';
    }

    public function area(): string
    {
        return 'banners';
    }

    public function run(array $input, Context $context): array
    {
        $slides = [];
        /** @var SlideCollection $slideCollection */
        $slideCollection = $this->objectManager->create(SlideCollection::class);
        foreach ($slideCollection->setOrder('position', 'ASC') as $slide) {
            $slides[(int) $slide->getData('banner_id')][] = [
                'id' => (int) $slide->getId(),
                'position' => (int) $slide->getData('position'),
                'active' => (bool) $slide->getData('is_active'),
                'heading' => $slide->getData('heading'),
                'eyebrow' => $slide->getData('eyebrow'),
                'text' => $slide->getData('text'),
                'button_text' => $slide->getData('button_text'),
                'link_url' => $slide->getData('link_url'),
                'image' => $slide->getData('image'),
                'date_from' => $slide->getData('date_from'),
                'date_to' => $slide->getData('date_to'),
            ];
        }
        $banners = [];
        /** @var BannerCollection $bannerCollection */
        $bannerCollection = $this->objectManager->create(BannerCollection::class);
        foreach ($bannerCollection as $banner) {
            $banners[] = [
                'id' => (int) $banner->getId(),
                'name' => $banner->getData('name'),
                'identifier' => $banner->getData('identifier'),
                'active' => (bool) $banner->getData('is_active'),
                'slides' => $slides[(int) $banner->getId()] ?? [],
            ];
        }

        return ['banners' => $banners];
    }
}
