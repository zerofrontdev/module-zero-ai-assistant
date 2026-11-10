<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\System;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;

/**
 * flush_cache: Cache Management's Flush Magento Cache, or the types named
 * (the invalidated ones by default).
 */
class FlushCache implements ToolInterface
{
    public function __construct(
        private readonly TypeListInterface $cacheTypes,
        private readonly EventManager $events
    ) {
    }

    public function name(): string
    {
        return 'flush_cache';
    }

    public function description(): string
    {
        return 'Flush the store\'s caches: the types given (codes from store_status, e.g. config, full_page, block_html), or with none given every type. Shown to the admin to confirm first. Not needed after an ordinary product, category or page change: those clean their own pages by tags.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'types' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Cache type codes; none: all'],
        ]];
    }

    public function resource(): string
    {
        return 'Magento_Backend::cache';
    }

    public function area(): string
    {
        return 'system';
    }

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        $types = $this->types($input);

        return [
            'title' => count($types) === count($this->cacheTypes->getTypes()) ? (string) __('Flush every cache') : (string) __('Flush %1 cache type(s)', count($types)),
            'lines' => [(string) __('Types') => implode(', ', $types)],
        ];
    }

    public function run(array $input, Context $context): array
    {
        $types = $this->types($input);
        foreach ($types as $type) {
            $this->cacheTypes->cleanType($type);
        }
        if (count($types) === count($this->cacheTypes->getTypes())) {
            // What the admin's button does too: Varnish and the like purge on this.
            $this->events->dispatch('adminhtml_cache_flush_system');
        }

        return ['flushed' => $types];
    }

    /**
     * @return string[]
     * @throws LocalizedException
     */
    private function types(array $input): array
    {
        $known = array_keys($this->cacheTypes->getTypes());
        $wanted = array_values(array_unique(array_map('strval', (array) ($input['types'] ?? []))));
        if (!$wanted) {
            return $known;
        }
        $unknown = array_diff($wanted, $known);
        if ($unknown) {
            throw new LocalizedException(__('No cache type %1. Known: %2.', implode(', ', $unknown), implode(', ', $known)));
        }

        return $wanted;
    }
}
