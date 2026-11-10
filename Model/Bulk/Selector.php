<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Bulk;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * The products a bulk job is for: by SKUs, a category, a word in the name,
 * and only those with an empty field to write; the default values (store 0).
 */
class Selector
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param array{skus?: string[], category_id?: int, name_contains?: string, only_empty?: bool} $filter
     * @param string[] $fields the fields to write (only_empty looks at them)
     * @return array<int, array{id: int, sku: string, name: string}> by product id
     * @throws LocalizedException when nothing is chosen at all
     */
    public function select(array $filter, array $fields, int $limit): array
    {
        $skus = array_values(array_filter(array_map(static fn ($s) => trim((string) $s), (array) ($filter['skus'] ?? []))));
        $category = (int) ($filter['category_id'] ?? 0);
        $name = trim((string) ($filter['name_contains'] ?? ''));
        if (!$skus && !$category && $name === '' && empty($filter['only_empty'])) {
            throw new LocalizedException(__('Say which products: SKUs, a category, a word in the name, or only those with empty texts.'));
        }
        $collection = $this->collectionFactory->create()->setStoreId(0)->addAttributeToSelect(['name', ...$fields]);
        if ($skus) {
            $collection->addAttributeToFilter('sku', ['in' => $skus]);
        }
        if ($category) {
            $collection->addCategoriesFilter(['in' => [$category]]);
        }
        if ($name !== '') {
            $collection->addAttributeToFilter('name', ['like' => '%' . addcslashes($name, '%_') . '%']);
        }
        if (!empty($filter['only_empty']) && $fields) {
            $empty = [];
            foreach ($fields as $field) {
                $empty[] = ['attribute' => $field, 'null' => true];
                $empty[] = ['attribute' => $field, 'eq' => ''];
            }
            $collection->addAttributeToFilter($empty, null, 'left');
        }
        $collection->setOrder('entity_id', 'ASC')->setPageSize($limit + 1);
        $products = [];
        foreach ($collection as $product) {
            $products[(int) $product->getId()] = ['id' => (int) $product->getId(), 'sku' => (string) $product->getSku(), 'name' => (string) $product->getName()];
        }
        return $products;
    }
}
