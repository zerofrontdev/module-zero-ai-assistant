<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Catalog;

use Zero\AiAssistant\Model\ModelHtml;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReversibleInterface;
use Zero\AiAssistant\Model\Tool\ToolInterface;

class UpdateProduct implements ToolInterface, ReversibleInterface
{
    private const FIELDS = ['name', 'price', 'special_price', 'qty', 'enabled', 'category_ids', 'description',
        'short_description', 'meta_title', 'meta_description', 'url_key'];

    public function __construct(private readonly ProductWriter $writer, private readonly ModelHtml $html)
    {
    }

    public function name(): string
    {
        return 'update_product';
    }

    public function description(): string
    {
        return 'Change a product, by SKU (shown to the admin to confirm first): only the fields given change. special_price null removes it. category_ids replaces the categories. add_image_upload_ids adds uploaded images to its gallery.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'sku' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'price' => ['type' => 'number'],
            'special_price' => ['type' => ['number', 'null']],
            'qty' => ['type' => 'number'],
            'enabled' => ['type' => 'boolean'],
            'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'description' => ['type' => 'string'],
            'short_description' => ['type' => 'string'],
            'meta_title' => ['type' => 'string'],
            'meta_description' => ['type' => 'string'],
            'url_key' => ['type' => 'string'],
            'add_image_upload_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ], 'required' => ['sku']];
    }

    public function resource(): string
    {
        return 'Magento_Catalog::products';
    }

    public function area(): string
    {
        return 'catalog';
    }

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        $product = $this->writer->get((string) ($input['sku'] ?? ''));
        foreach ((array) ($input['add_image_upload_ids'] ?? []) as $uploadId) {
            $context->upload((int) $uploadId);
        }
        $lines = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            $new = is_array($value) ? implode(', ', $value) : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? '—'));
            $old = $field === 'enabled' ? ((int) $product->getStatus() === 1 ? 'yes' : 'no')
                : (is_array($product->getData($field)) ? implode(', ', $product->getData($field)) : (string) $product->getData($field));
            if (in_array($field, ['description', 'short_description'], true)) {
                // What will be saved, and where its links go.
                $lines += $this->html->summary($field, $this->html->clean($new, $old));
                continue;
            }
            $lines[$field] = mb_substr(strip_tags($old), 0, 80) . ' → ' . mb_substr(strip_tags($new), 0, 200);
        }
        if (!empty($input['add_image_upload_ids'])) {
            $lines['images'] = '+ ' . implode(', ', array_map(static fn ($id) => '#' . $id, (array) $input['add_image_upload_ids']));
        }

        return ['title' => (string) __('Change product %1 "%2"', $product->getSku(), $product->getName()), 'lines' => $lines];
    }

    /**
     * The fields the change gives, as they are now (images aside: added
     * images stay).
     */
    public function snapshot(array $input, Context $context): array
    {
        $product = $this->writer->get((string) ($input['sku'] ?? ''));
        $before = ['sku' => $product->getSku()];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $before[$field] = match ($field) {
                'enabled' => (int) $product->getStatus() === 1,
                'qty' => (float) ($product->getExtensionAttributes()?->getStockItem()?->getQty() ?? 0),
                'category_ids' => array_map('intval', (array) $product->getCategoryIds()),
                'price' => (float) $product->getPrice(),
                'special_price' => $product->getSpecialPrice() === null || $product->getSpecialPrice() === '' ? null : (float) $product->getSpecialPrice(),
                default => (string) $product->getData($field),
            };
        }
        return $before;
    }

    public function restore(array $snapshot, Context $context): array
    {
        $product = $this->writer->restore($snapshot, $context);
        return ['id' => (int) $product->getId(), 'sku' => $product->getSku(), 'restored' => implode(', ', array_diff(array_keys($snapshot), ['sku'])), 'edit_url' => $this->writer->editUrl($product)];
    }

    public function run(array $input, Context $context): array
    {
        $product = $this->writer->update($input, $context);

        return ['id' => (int) $product->getId(), 'sku' => $product->getSku(), 'edit_url' => $this->writer->editUrl($product)];
    }
}
