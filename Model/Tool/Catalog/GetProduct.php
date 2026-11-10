<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Catalog;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;

class GetProduct extends ReadTool
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    public function name(): string
    {
        return 'get_product';
    }

    public function description(): string
    {
        return 'One product in full, by SKU: prices, stock, status, categories, texts, images, and for a configurable product its variants.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']], 'required' => ['sku']];
    }

    public function resource(): string
    {
        return 'Magento_Catalog::products';
    }

    public function area(): string
    {
        return 'catalog';
    }

    public function run(array $input, Context $context): array
    {
        try {
            $product = $this->productRepository->get((string) $input['sku'], false, 0, true);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product with SKU %1.', (string) $input['sku']));
        }
        $children = [];
        if ($product->getTypeId() === 'configurable') {
            foreach ($product->getTypeInstance()->getUsedProducts($product) as $child) {
                $children[] = [
                    'sku' => $child->getSku(),
                    'name' => $child->getName(),
                    'qty' => (float) $this->stockRegistry->getStockItem((int) $child->getId())->getQty(),
                ];
            }
        }
        $stock = $this->stockRegistry->getStockItem((int) $product->getId());

        return [
            'id' => (int) $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'type' => $product->getTypeId(),
            'price' => (float) $product->getPrice(),
            'special_price' => $product->getSpecialPrice() !== null ? (float) $product->getSpecialPrice() : null,
            'enabled' => (int) $product->getStatus() === 1,
            'visibility' => (int) $product->getVisibility(),
            'qty' => (float) $stock->getQty(),
            'in_stock' => (bool) $stock->getIsInStock(),
            'category_ids' => array_map('intval', (array) $product->getCategoryIds()),
            'url_key' => $product->getUrlKey(),
            'short_description' => $this->plain($product->getShortDescription(), 400),
            'description' => $this->plain($product->getDescription(), 1200),
            'meta_title' => $product->getMetaTitle(),
            'meta_description' => $product->getMetaDescription(),
            'images' => count((array) $product->getMediaGalleryEntries()),
            'variants' => $children,
        ];
    }
}
