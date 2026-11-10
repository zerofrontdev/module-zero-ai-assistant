<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Cms;

use Zero\AiAssistant\Model\ModelHtml;
use Magento\Framework\Exception\LocalizedException;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\AiAssistant\Model\Uploads;

/**
 * save_cms_page / save_cms_block (etc/di.xml). Uploaded photos go into the
 * HTML as {{upload:N}} where their URL belongs; on save they are published
 * under pub/media/wysiwyg/zero-ai and the placeholder becomes a {{media}}
 * directive.
 */
class SaveContent implements ToolInterface
{
    public const MEDIA_DIR = 'wysiwyg/zero-ai';
    private const PLACEHOLDER = '/\{\{\s*upload:\s*(\d+)\s*\}\}/';

    public function __construct(
        private readonly Content $content,
        private readonly Uploads $uploads,
        private readonly ModelHtml $html,
        private readonly string $type = 'page'
    ) {
    }

    /**
     * The upload ids the content's {{upload:N}} placeholders name.
     *
     * @return int[]
     */
    public static function uploadIds(string $content): array
    {
        preg_match_all(self::PLACEHOLDER, $content, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * The placeholders replaced by {{media url="…"}} for the paths given (upload id => path under pub/media).
     */
    public static function withMedia(string $content, array $paths): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            static fn (array $m) => isset($paths[(int) $m[1]]) ? '{{media url="' . $paths[(int) $m[1]] . '"}}' : $m[0],
            $content
        );
    }

    public function name(): string
    {
        return 'save_cms_' . $this->type;
    }

    public function description(): string
    {
        return 'Create a CMS ' . $this->type . ' (no id: title, identifier and content required; on all store views) or change one (id; only the fields given change). Content is HTML; an uploaded photo goes in as <img src="{{upload:N}}" alt="…"> (its upload id), published on save. Shown to the admin to confirm first. A page built with Zero CMS components shows those, not this HTML: change those with save_cms_components.';
    }

    public function schema(): array
    {
        $properties = [
            'id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'identifier' => ['type' => 'string', 'description' => 'URL key of a page, code of a block'],
            'content' => ['type' => 'string', 'description' => 'HTML'],
            'active' => ['type' => 'boolean'],
        ];
        if ($this->type === 'page') {
            $properties += [
                'content_heading' => ['type' => 'string'],
                'meta_title' => ['type' => 'string'],
                'meta_description' => ['type' => 'string'],
            ];
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    public function resource(): string
    {
        return $this->type === 'page' ? 'Magento_Cms::save' : 'Magento_Cms::block';
    }

    public function area(): string
    {
        return 'cms';
    }

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        if (empty($input['id'])) {
            foreach (['title', 'identifier', 'content'] as $field) {
                if (trim((string) ($input[$field] ?? '')) === '') {
                    throw new LocalizedException(__('A new CMS %1 needs a %2.', $this->type, $field));
                }
            }
            $title = (string) __('Create CMS %1 "%2"', $this->type, $input['title']);
        } else {
            $item = $this->content->get($this->type, (int) $input['id']);
            $title = (string) __('Change CMS %1 "%2" (#%3)', $this->type, $item->getData('title'), $item->getId());
        }
        $lines = [];
        foreach ($input as $field => $value) {
            if ($field === 'content') {
                // What will be saved, and where its links go.
                $lines += $this->html->summary($field, $this->html->clean((string) $value, isset($item) ? (string) $item->getData('content') : ''));
            } elseif ($field !== 'id') {
                $lines[$field] = is_bool($value) ? ($value ? 'yes' : 'no') : mb_substr(trim(strip_tags((string) $value)), 0, 240);
            }
        }
        $uploadIds = self::uploadIds((string) ($input['content'] ?? ''));
        if ($uploadIds) {
            $lines['images'] = implode(', ', array_map(fn (int $id) => '#' . $context->upload($id)['upload_id'], $uploadIds));
        }

        return ['title' => $title, 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        if (isset($input['content'])) {
            $paths = [];
            foreach (self::uploadIds((string) $input['content']) as $id) {
                $paths[$id] = $this->uploads->publish($context->upload($id), self::MEDIA_DIR);
            }
            $input['content'] = self::withMedia((string) $input['content'], $paths);
        }
        $item = $this->content->save($this->type, $input);

        return ['id' => (int) $item->getId(), 'identifier' => $item->getData('identifier')];
    }
}
