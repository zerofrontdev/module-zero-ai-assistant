<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Cms;

use Magento\Framework\Exception\LocalizedException;
use Magento\User\Model\UserFactory;
use Zero\AiAssistant\Model\Acl\UserAuthorizationFactory;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\Cms\Model\Content\Validator;

/**
 * save_cms_components: a CMS page's or block's Zero CMS components, as the Zero
 * CMS editor saves them (the same validation; the previous version kept as a
 * revision). Can create the page first (`new_page`). Photos uploaded in the
 * conversation go into image fields as {"upload": N, "alt": "…"}.
 */
class SaveComponents implements ToolInterface
{
    public function __construct(
        private readonly Components $components,
        private readonly Content $content,
        private readonly Validator $validator,
        private readonly UserAuthorizationFactory $authorizationFactory,
        private readonly UserFactory $userFactory
    ) {
    }

    public function name(): string
    {
        return 'save_cms_components';
    }

    public function description(): string
    {
        return 'Set the Zero CMS components of a CMS page (page_id) or block (block_id), or create a page with them (new_page: title, identifier = URL key, optional meta_title, meta_description, active; on all store views). `components` is the whole list in order, replacing what is there (get_cms_components first to change one): [{type, data}], types and fields from list_cms_components. Links are {label, url}; an image is {path, alt} under pub/media, or {upload: N, alt} for a photo uploaded in this conversation. In headings *words between asterisks* are emphasised. store_id 0 (default) saves the version for all store views. Shown to the admin to confirm first; the previous version is kept as a revision in the Zero CMS editor.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'block_id' => ['type' => 'integer'],
                'new_page' => ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string'],
                    'identifier' => ['type' => 'string'],
                    'meta_title' => ['type' => 'string'],
                    'meta_description' => ['type' => 'string'],
                    'active' => ['type' => 'boolean'],
                ], 'required' => ['title', 'identifier']],
                'store_id' => ['type' => 'integer'],
                'components' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'type' => ['type' => 'string'],
                    'data' => ['type' => 'object'],
                ], 'required' => ['type', 'data']]],
                'note' => ['type' => 'string', 'description' => 'What changed, for the revision list'],
            ],
            'required' => ['components'],
        ];
    }

    public function resource(): string
    {
        return 'Zero_Cms::editor';
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
        $components = $this->check($input, $context);
        $lines = [];
        if (!empty($input['new_page'])) {
            $title = (string) __('Create CMS page "%1" (/%2) with %3 components', $input['new_page']['title'], $input['new_page']['identifier'], count($components));
        } else {
            $target = $this->components->target($input);
            $title = (string) __('Set the components of CMS %1 "%2" (#%3)', $target['type'], $target['item']->getData('title'), $target['item']->getId());
        }
        $lines[(string) __('Store view')] = (int) ($input['store_id'] ?? 0) === 0 ? (string) __('All store views') : '#' . (int) $input['store_id'];
        $clean = $this->validator->clean(['components' => $this->withoutUploads($components)]);
        foreach ($clean['components'] as $i => $component) {
            $definition = $this->components->registry()->get($component['type']);
            $line = (string) ($definition['label'] ?? $component['type']);
            $text = $this->firstText($component['data']);
            $missing = $this->validator->missingRequired($component);
            $lines[($i + 1) . '. ' . $component['type']] = $line . ($text !== '' ? ': ' . $text : '')
                . ($missing ? ' (' . __('empty: %1', implode(', ', $missing)) . ')' : '');
        }
        $uploads = $this->components->uploadIds($components);
        if ($uploads) {
            $lines[(string) __('Photos')] = implode(', ', array_map(static fn (int $id) => '#' . $id, $uploads));
        }

        return ['title' => $title, 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        $components = $this->components->withUploads($this->check($input, $context), $context);
        if (!empty($input['new_page'])) {
            $page = $this->content->save('page', [
                'title' => (string) $input['new_page']['title'],
                'identifier' => (string) $input['new_page']['identifier'],
                'content' => '',
                'active' => (bool) ($input['new_page']['active'] ?? true),
            ] + array_intersect_key((array) $input['new_page'], array_flip(['meta_title', 'meta_description'])));
            $input['page_id'] = (int) $page->getId();
        }
        $target = $this->components->target($input);
        $user = $this->userFactory->create()->load($context->userId);
        $saved = $this->components->save(
            $target,
            (int) ($input['store_id'] ?? 0),
            $components,
            trim('AI: ' . $user->getUserName()),
            (string) ($input['note'] ?? '')
        );
        $warnings = [];
        foreach ($saved['components'] as $component) {
            foreach ($this->validator->missingRequired($component) as $label) {
                $warnings[] = (string) __('%1: "%2" is empty.', $component['type'], $label);
            }
        }

        return array_filter([
            $target['type'] . '_id' => (int) $target['item']->getId(),
            'identifier' => $target['item']->getData('identifier'),
            'components' => count($saved['components']),
            'warnings' => $warnings ?: null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * The components, after checking what can be told before saving.
     *
     * @throws LocalizedException
     */
    private function check(array $input, Context $context): array
    {
        $components = array_values(array_filter((array) ($input['components'] ?? []), 'is_array'));
        $unknown = $this->components->unknownTypes($components);
        if ($unknown) {
            throw new LocalizedException(__('Unknown component type: %1. list_cms_components gives the types.', implode(', ', $unknown)));
        }
        if (!empty($input['new_page'])) {
            if (!empty($input['page_id']) || !empty($input['block_id'])) {
                throw new LocalizedException(__('Either new_page or page_id/block_id, not both.'));
            }
            // The admin user's own role, also through the Claude connector (where there is no admin session).
            if (!$this->authorizationFactory->create(['userId' => $context->userId])->isAllowed('Magento_Cms::save')) {
                throw new LocalizedException(__('Your role may not create CMS pages.'));
            }
            foreach (['title', 'identifier'] as $field) {
                if (trim((string) ($input['new_page'][$field] ?? '')) === '') {
                    throw new LocalizedException(__('A new CMS page needs a %1.', $field));
                }
            }
        } else {
            $this->components->target($input);
        }
        foreach ($this->components->uploadIds($components) as $id) {
            $context->upload($id);
        }

        return $components;
    }

    /** Image fields given as uploads, as an empty image for the preview's validation. */
    private function withoutUploads(array $components): array
    {
        $strip = static function ($value) use (&$strip) {
            if (!is_array($value)) {
                return $value;
            }
            if (isset($value['upload']) && is_numeric($value['upload'])) {
                return ['path' => '', 'alt' => (string) ($value['alt'] ?? '')];
            }
            return array_map($strip, $value);
        };

        return array_map($strip, $components);
    }

    /** The first heading or text of a component, short, for the preview. */
    private function firstText(array $data): string
    {
        foreach (['heading', 'title', 'eyebrow', 'text', 'body', 'html'] as $key) {
            if (is_string($data[$key] ?? null) && trim($data[$key]) !== '') {
                $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($data[$key])));
                return mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '…' : $text;
            }
        }

        return '';
    }
}
