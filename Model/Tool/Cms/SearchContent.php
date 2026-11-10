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
 * search_cms_pages / search_cms_blocks (etc/di.xml).
 */
class SearchContent extends ReadTool
{
    public function __construct(
        private readonly Content $content,
        private readonly string $type = 'page'
    ) {
    }

    public function name(): string
    {
        return 'search_cms_' . $this->type . 's';
    }

    public function description(): string
    {
        return 'Find CMS ' . $this->type . 's by words in the title or identifier (empty query: the recently changed). Returns id, title, identifier, active.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
        ]];
    }

    public function resource(): string
    {
        return $this->type === 'page' ? 'Magento_Cms::page' : 'Magento_Cms::block';
    }

    public function area(): string
    {
        return 'cms';
    }

    public function run(array $input, Context $context): array
    {
        return [$this->type . 's' => $this->content->search(
            $this->type,
            trim((string) ($input['query'] ?? '')),
            min(50, max(1, (int) ($input['limit'] ?? 20)))
        )];
    }
}
