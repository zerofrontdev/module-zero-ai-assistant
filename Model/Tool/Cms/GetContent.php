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
 * get_cms_page / get_cms_block (etc/di.xml).
 */
class GetContent extends ReadTool
{
    public function __construct(
        private readonly Content $content,
        private readonly string $type = 'page'
    ) {
    }

    public function name(): string
    {
        return 'get_cms_' . $this->type;
    }

    public function description(): string
    {
        return 'One CMS ' . $this->type . ' by id, with its HTML content. A ' . $this->type . ' built with Zero CMS components shows those instead: get_cms_components.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];
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
        $item = $this->content->get($this->type, (int) $input['id']);
        $html = (string) $item->getData('content');

        return array_filter([
            'id' => (int) $item->getId(),
            'title' => $item->getData('title'),
            'identifier' => $item->getData('identifier'),
            'active' => (bool) $item->getData('is_active'),
            'content_heading' => $item->getData('content_heading'),
            'meta_title' => $item->getData('meta_title'),
            'meta_description' => $item->getData('meta_description'),
            'content' => mb_strlen($html) > 20000 ? mb_substr($html, 0, 20000) . '…' : $html,
        ], static fn ($v) => $v !== null);
    }
}
