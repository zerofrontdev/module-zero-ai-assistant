<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Cms;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Uploads;
use Zero\Cms\Model\Component\Registry;
use Zero\Cms\Model\Content\Repository;

/**
 * Zero CMS components of a CMS page or block, for the component tools: the
 * page or block they belong to, uploaded photos in image fields, and the save
 * the Zero CMS editor does (a revision of the previous version, the page cache
 * of the page or block cleaned).
 */
class Components
{
    public const MEDIA_DIR = 'wysiwyg/zero-ai';

    public function __construct(
        private readonly Content $content,
        private readonly Registry $registry,
        private readonly Repository $repository,
        private readonly Uploads $uploads,
        private readonly ManagerInterface $eventManager,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * The page or block the input names: `page_id` or `block_id`.
     *
     * @return array{type: string, entity_type: string, item: AbstractModel}
     * @throws LocalizedException
     */
    public function target(array $input): array
    {
        if (!empty($input['page_id'])) {
            return ['type' => 'page', 'entity_type' => 'cms_page', 'item' => $this->content->get('page', (int) $input['page_id'])];
        }
        if (!empty($input['block_id'])) {
            return ['type' => 'block', 'entity_type' => 'cms_block', 'item' => $this->content->get('block', (int) $input['block_id'])];
        }
        throw new LocalizedException(__('Name the CMS page (page_id) or block (block_id).'));
    }

    /**
     * Upload ids in image fields given as {"upload": N, "alt": "…"}, anywhere in the components.
     *
     * @return int[]
     */
    public function uploadIds(array $components): array
    {
        $ids = [];
        $walk = static function ($value) use (&$walk, &$ids): void {
            if (!is_array($value)) {
                return;
            }
            if (isset($value['upload']) && is_numeric($value['upload'])) {
                $ids[] = (int) $value['upload'];
                return;
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($components);

        return array_values(array_unique($ids));
    }

    /**
     * The components with each {"upload": N} published under pub/media and
     * written as the image field's {"path", "alt"}.
     */
    public function withUploads(array $components, Context $context): array
    {
        $paths = [];
        foreach ($this->uploadIds($components) as $id) {
            $paths[$id] = $this->uploads->publish($context->upload($id), self::MEDIA_DIR);
        }
        $replace = static function ($value) use (&$replace, $paths) {
            if (!is_array($value)) {
                return $value;
            }
            if (isset($value['upload']) && is_numeric($value['upload']) && isset($paths[(int) $value['upload']])) {
                return ['path' => $paths[(int) $value['upload']], 'alt' => (string) ($value['alt'] ?? '')];
            }
            return array_map($replace, $value);
        };

        return array_map($replace, $components);
    }

    /**
     * Component types the input uses that no module defines.
     *
     * @return string[]
     */
    public function unknownTypes(array $components): array
    {
        $unknown = [];
        foreach ($components as $component) {
            $type = is_array($component) ? (string) ($component['type'] ?? '') : '';
            if ($this->registry->get($type) === null) {
                $unknown[] = $type === '' ? '(no type)' : $type;
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * Saves the components as the Zero CMS editor does.
     *
     * @return array{components: array}
     */
    public function save(array $target, int $storeId, array $components, string $user, string $note): array
    {
        $saved = $this->repository->save($target['entity_type'], (int) $target['item']->getId(), $storeId, ['components' => $components], $user, $note);
        if ($target['item'] instanceof IdentityInterface) {
            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $target['item']]);
            $this->cache->clean($target['item']->getIdentities());
        }

        return $saved;
    }

    public function repository(): Repository
    {
        return $this->repository;
    }

    public function registry(): Registry
    {
        return $this->registry;
    }
}
