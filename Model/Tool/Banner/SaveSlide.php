<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Banner;

use Zero\AiAssistant\Model\ModelHtml;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\AiAssistant\Model\Uploads;
use Zero\Banner\Model\Banner;
use Zero\Banner\Model\ImageUploader;
use Zero\Banner\Model\ResourceModel\Banner as BannerResource;
use Zero\Banner\Model\ResourceModel\Slide as SlideResource;
use Zero\Banner\Model\ResourceModel\Slide\Collection as SlideCollection;
use Zero\Banner\Model\Slide;
use Zero\Banner\Model\SlidePicture;

/**
 * save_slide: a banner slide added or changed, its picture from an
 * uploaded photo, the banner's pages refreshed.
 */
class SaveSlide implements ToolInterface
{
    private const FIELDS = ['heading', 'eyebrow', 'text', 'button_text', 'link_url', 'alt', 'image_fit', 'image_position', 'content_position'];

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly Uploads $uploads,
        private readonly EventManager $events,
        private readonly ModelHtml $html
    ) {
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function bannerResource(): BannerResource
    {
        return $this->objectManager->get(BannerResource::class);
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function slideResource(): SlideResource
    {
        return $this->objectManager->get(SlideResource::class);
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function imageUploader(): ImageUploader
    {
        return $this->objectManager->get(ImageUploader::class);
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function slidePicture(): SlidePicture
    {
        return $this->objectManager->get(SlidePicture::class);
    }

    public function name(): string
    {
        return 'save_slide';
    }

    public function description(): string
    {
        return 'Add a slide to a banner (banner_id from list_banners; a new slide needs an image_upload_id) or change one (slide_id; only the fields given change). Texts: heading, eyebrow, text, button_text, link_url, alt; active; date_from/date_to (YYYY-MM-DD) for a campaign period; position (0 first). Shown to the admin to confirm first.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'banner_id' => ['type' => 'integer'],
            'slide_id' => ['type' => 'integer'],
            'heading' => ['type' => 'string'],
            'eyebrow' => ['type' => 'string'],
            'text' => ['type' => 'string'],
            'button_text' => ['type' => 'string'],
            'link_url' => ['type' => 'string'],
            'alt' => ['type' => 'string'],
            'active' => ['type' => 'boolean'],
            'position' => ['type' => 'integer'],
            'date_from' => ['type' => 'string'],
            'date_to' => ['type' => 'string'],
            'image_upload_id' => ['type' => 'integer'],
        ]];
    }

    public function resource(): string
    {
        return 'Zero_Banner::banner_save';
    }

    public function area(): string
    {
        return 'banners';
    }

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        [$banner, $slide] = $this->load($input);
        if (!$slide->getId() && empty($input['image_upload_id'])) {
            throw new LocalizedException(__('A new slide needs a photo (image_upload_id).'));
        }
        foreach (['date_from', 'date_to'] as $date) {
            if (!empty($input[$date]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input[$date])) {
                throw new LocalizedException(__('%1 must be YYYY-MM-DD.', $date));
            }
        }
        if (isset($input['link_url']) && !ModelHtml::isSafeUrl((string) $input['link_url'])) {
            throw new LocalizedException(__('link_url must be a page of the store or an http(s) address.'));
        }
        $lines = ['Banner' => $banner->getData('name') . ' (' . $banner->getData('identifier') . ')'];
        foreach ($input as $field => $value) {
            if (in_array($field, ['banner_id', 'slide_id'], true)) {
                continue;
            }
            $lines[$field] = $field === 'image_upload_id' ? '#' . $context->upload((int) $value)['upload_id'] : (is_bool($value) ? ($value ? 'yes' : 'no') : mb_substr((string) $value, 0, 200));
        }

        return ['title' => $slide->getId() ? (string) __('Change slide #%1', $slide->getId()) : (string) __('Add a slide to "%1"', $banner->getData('name')), 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        [$banner, $slide] = $this->load($input);
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $value = $this->html->text(mb_substr(trim((string) $input[$field]), 0, 1000));
                if ($field === 'link_url' && !ModelHtml::isSafeUrl($value)) {
                    throw new LocalizedException(__('link_url must be a page of the store or an http(s) address.'));
                }
                $slide->setData($field, $value);
            }
        }
        foreach (['date_from', 'date_to'] as $date) {
            if (array_key_exists($date, $input)) {
                $slide->setData($date, (string) $input[$date] ?: null);
            }
        }
        if (array_key_exists('active', $input) || !$slide->getId()) {
            $slide->setData('is_active', (int) (bool) ($input['active'] ?? true));
        }
        if (array_key_exists('position', $input)) {
            $slide->setData('position', (int) $input['position']);
        } elseif (!$slide->getId()) {
            $slide->setData('position', $this->nextPosition((int) $banner->getId()));
        }
        if (!empty($input['image_upload_id'])) {
            $slide->setData('image', '/' . $this->uploads->publish($context->upload((int) $input['image_upload_id']), $this->imageUploader()->getBasePath()));
        }
        $slide->setData('banner_id', (int) $banner->getId());
        $this->slideResource()->save($slide);
        $this->slidePicture()->build($banner, $slide);
        $this->events->dispatch('clean_cache_by_tags', ['object' => $banner]);

        return ['banner_id' => (int) $banner->getId(), 'slide_id' => (int) $slide->getId(), 'image' => $slide->getData('image'), 'position' => (int) $slide->getData('position')];
    }

    /**
     * @return array{0: Banner, 1: Slide}
     * @throws LocalizedException
     */
    private function load(array $input): array
    {
        /** @var Slide $slide */
        $slide = $this->objectManager->create(Slide::class);
        if (!empty($input['slide_id'])) {
            $this->slideResource()->load($slide, (int) $input['slide_id']);
            if (!$slide->getId()) {
                throw new LocalizedException(__('No slide #%1.', $input['slide_id']));
            }
        }
        $bannerId = (int) ($input['banner_id'] ?? 0) ?: (int) $slide->getData('banner_id');
        /** @var Banner $banner */
        $banner = $this->objectManager->create(Banner::class);
        $this->bannerResource()->load($banner, $bannerId);
        if (!$banner->getId()) {
            throw new LocalizedException(__('No banner #%1; list_banners shows them.', $bannerId));
        }

        return [$banner, $slide];
    }

    private function nextPosition(int $bannerId): int
    {
        /** @var SlideCollection $collection */
        $collection = $this->objectManager->create(SlideCollection::class);
        $max = -1;
        foreach ($collection->addBannerFilter($bannerId) as $slide) {
            $max = max($max, (int) $slide->getData('position'));
        }

        return $max + 1;
    }
}
