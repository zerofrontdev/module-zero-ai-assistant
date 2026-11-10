<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Cms;

use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;

/**
 * get_cms_components: the Zero CMS components of a CMS page or block, for
 * all store views or one store view's own version.
 */
class GetComponents extends ReadTool
{
    public function __construct(
        private readonly Components $components
    ) {
    }

    public function name(): string
    {
        return 'get_cms_components';
    }

    public function description(): string
    {
        return 'The Zero CMS components of a CMS page (page_id) or block (block_id): what the storefront shows instead of the HTML content when there are any. store_id 0 (default) is the version for all store views; another id gives that store view\'s own version, if it has one. Each component has its type and data, as save_cms_components takes them.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'page_id' => ['type' => 'integer'],
            'block_id' => ['type' => 'integer'],
            'store_id' => ['type' => 'integer'],
        ]];
    }

    public function resource(): string
    {
        return 'Zero_Cms::editor';
    }

    public function area(): string
    {
        return 'cms';
    }

    public function run(array $input, Context $context): array
    {
        $target = $this->components->target($input);
        $storeId = (int) ($input['store_id'] ?? 0);
        $own = $this->components->repository()->getOwn($target['entity_type'], (int) $target['item']->getId(), $storeId);

        return [
            $target['type'] . '_id' => (int) $target['item']->getId(),
            'title' => $target['item']->getData('title'),
            'store_id' => $storeId,
            'has_components' => $own !== null && !empty($own['components']),
            'components' => array_map(
                static fn (array $c) => ['type' => $c['type'], 'data' => $c['data']],
                (array) ($own['components'] ?? [])
            ),
        ];
    }
}
