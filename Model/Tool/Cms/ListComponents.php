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
 * list_cms_components: the Zero CMS component types this store has (every
 * module's etc/zero_cms/components.json) with their fields, so Claude can fill
 * them in for save_cms_components.
 */
class ListComponents extends ReadTool
{
    public function __construct(
        private readonly Components $components
    ) {
    }

    public function name(): string
    {
        return 'list_cms_components';
    }

    public function description(): string
    {
        return 'The Zero CMS component types a CMS page or block can be built from (hero, tiles, products, faq, a theme\'s own like bu_hero or kk_hero…), each with its fields: type (text, textarea, link {label, url}, image {path, alt}, select with options, boolean, number, repeater with its own fields, markdown, html, datetime), label, required, max. `prefix` narrows the list (e.g. "bu_"). Use before save_cms_components.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['prefix' => ['type' => 'string', 'description' => 'Only types starting with this']]];
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
        $prefix = (string) ($input['prefix'] ?? '');
        $types = [];
        foreach ($this->components->registry()->getAll() as $code => $definition) {
            if ($prefix !== '' && !str_starts_with((string) $code, $prefix)) {
                continue;
            }
            $types[] = [
                'type' => $code,
                'label' => (string) ($definition['label'] ?? $code),
                'description' => (string) ($definition['description'] ?? ''),
                'fields' => $this->fields((array) ($definition['fields'] ?? [])),
            ];
        }

        return ['types' => $types];
    }

    private function fields(array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $field) {
            $field = (array) $field;
            $out[$name] = array_filter([
                'type' => $field['type'] ?? 'text',
                'label' => $field['label'] ?? null,
                'required' => !empty($field['required']) ?: null,
                'max' => $field['max'] ?? null,
                'options' => isset($field['options']) ? array_keys((array) $field['options']) : null,
                'default' => $field['default'] ?? null,
                'fields' => isset($field['fields']) ? $this->fields((array) $field['fields']) : null,
            ], static fn ($v) => $v !== null);
        }

        return $out;
    }
}
