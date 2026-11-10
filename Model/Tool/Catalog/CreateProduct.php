<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Catalog;

use Zero\AiAssistant\Model\ModelHtml;
use Magento\Framework\Exception\LocalizedException;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;

class CreateProduct implements ToolInterface
{
    public function __construct(private readonly ProductWriter $writer, private readonly ModelHtml $html)
    {
    }

    public function name(): string
    {
        return 'create_product';
    }

    public function description(): string
    {
        return 'Create a product (shown to the admin to confirm first). A simple product, or with `variants` a configurable one over one dropdown attribute (size, color…), a hidden child per option. On every website, prices in the base currency, stock managed. `image_upload_ids`: images uploaded in this conversation (upload #N), the first becomes the main image. Write description and short_description as HTML (<p>, <ul>). Use list_categories for category ids.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'sku' => ['type' => 'string', 'description' => 'Empty: made from the name'],
            'price' => ['type' => 'number'],
            'special_price' => ['type' => 'number'],
            'qty' => ['type' => 'number', 'description' => 'Stock of a simple product (or of each variant without its own)'],
            'enabled' => ['type' => 'boolean', 'default' => true],
            'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'description' => ['type' => 'string'],
            'short_description' => ['type' => 'string'],
            'meta_title' => ['type' => 'string'],
            'meta_description' => ['type' => 'string'],
            'url_key' => ['type' => 'string'],
            'weight' => ['type' => 'number'],
            'image_upload_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'variants' => ['type' => 'object', 'properties' => [
                'attribute_code' => ['type' => 'string', 'description' => 'size, color…'],
                'options' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'label' => ['type' => 'string'],
                    'qty' => ['type' => 'number'],
                    'price' => ['type' => 'number'],
                ], 'required' => ['label']]],
            ], 'required' => ['attribute_code', 'options']],
        ], 'required' => ['name', 'price']];
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
        if (trim((string) ($input['name'] ?? '')) === '' || !is_numeric($input['price'] ?? null)) {
            throw new LocalizedException(__('A product needs a name and a price.'));
        }
        $sku = $this->writer->sku($input);
        if (!empty($input['sku']) && $this->writer->exists($sku)) {
            throw new LocalizedException(__('A product with SKU %1 already exists.', $sku));
        }
        foreach ((array) ($input['image_upload_ids'] ?? []) as $uploadId) {
            $context->upload((int) $uploadId);
        }
        $lines = [
            (string) __('SKU') => $sku,
            (string) __('Price') => (string) $input['price'] . (isset($input['special_price']) ? ' → ' . $input['special_price'] : ''),
        ];
        if (!empty($input['variants'])) {
            $this->writer->variantAttribute((string) ($input['variants']['attribute_code'] ?? ''));
            $lines[(string) __('Variants')] = $input['variants']['attribute_code'] . ': ' . implode(', ', array_map(
                static fn ($o) => $o['label'] . (isset($o['qty']) ? ' (' . $o['qty'] . ')' : ''),
                (array) $input['variants']['options']
            ));
        } else {
            $lines[(string) __('Qty')] = (string) ($input['qty'] ?? 0);
        }
        $lines[(string) __('Enabled')] = ($input['enabled'] ?? true) ? (string) __('Yes') : (string) __('No');
        if (!empty($input['category_ids'])) {
            $lines[(string) __('Categories')] = implode(', ', (array) $input['category_ids']);
        }
        if (!empty($input['image_upload_ids'])) {
            $lines[(string) __('Images')] = implode(', ', array_map(static fn ($id) => '#' . $id, (array) $input['image_upload_ids']));
        }
        foreach (['short_description' => (string) __('Short description'), 'description' => (string) __('Description')] as $field => $label) {
            if (!empty($input[$field])) {
                // What will be saved, and where its links go.
                $lines += $this->html->summary($label, $this->html->clean((string) $input[$field]));
            }
        }

        return ['title' => (string) __('Create product "%1"', $input['name']), 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        $product = $this->writer->create($input, $context);

        return ['id' => (int) $product->getId(), 'sku' => $product->getSku(), 'edit_url' => $this->writer->editUrl($product)];
    }
}
