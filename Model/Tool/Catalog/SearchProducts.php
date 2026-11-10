<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Catalog;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;

class SearchProducts extends ReadTool
{
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return 'Find products by words in the name or SKU (empty query: the newest). Returns id, SKU, name, type, price, status, visibility. Child variants are left out.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'description' => 'Words of the name, or (part of) the SKU'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'default' => 10],
        ]];
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
        $collection = $this->collectionFactory->create()
            ->addAttributeToSelect(['name', 'price', 'special_price', 'status', 'visibility'])
            ->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE])
            ->setOrder('entity_id', 'DESC')
            ->setPageSize(min(30, max(1, (int) ($input['limit'] ?? 10))));
        $query = trim((string) ($input['query'] ?? ''));
        if ($query !== '') {
            $collection->addAttributeToFilter([
                ['attribute' => 'name', 'like' => '%' . $query . '%'],
                ['attribute' => 'sku', 'like' => '%' . $query . '%'],
            ]);
        }
        $products = [];
        foreach ($collection as $product) {
            $products[] = [
                'id' => (int) $product->getId(),
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'type' => $product->getTypeId(),
                'price' => (float) $product->getPrice(),
                'special_price' => $product->getSpecialPrice() !== null ? (float) $product->getSpecialPrice() : null,
                'enabled' => (int) $product->getStatus() === 1,
            ];
        }

        return ['count' => count($products), 'products' => $products];
    }
}
