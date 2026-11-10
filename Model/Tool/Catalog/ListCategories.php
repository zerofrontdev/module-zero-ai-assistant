<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Catalog;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;

class ListCategories extends ReadTool
{
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    public function name(): string
    {
        return 'list_categories';
    }

    public function description(): string
    {
        return 'The category tree: id, name, its path of names, parent id, active or not, product count. Level 1 categories are the stores\' root categories.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function resource(): string
    {
        return 'Magento_Catalog::categories';
    }

    public function area(): string
    {
        return 'catalog';
    }

    public function run(array $input, Context $context): array
    {
        $collection = $this->collectionFactory->create()
            ->addAttributeToSelect(['name', 'is_active'])
            ->addFieldToFilter('level', ['gt' => 0])
            ->setOrder('path', 'ASC')
            ->setPageSize(500);
        $collection->setLoadProductCount(true);
        $names = [];
        $rows = [];
        foreach ($collection as $category) {
            $names[(int) $category->getId()] = (string) $category->getName();
            $rows[] = $category;
        }
        $categories = [];
        foreach ($rows as $category) {
            $path = array_slice(array_map('intval', explode('/', (string) $category->getPath())), 1);
            $categories[] = [
                'id' => (int) $category->getId(),
                'name' => $category->getName(),
                'path' => implode(' / ', array_map(static fn ($id) => $names[$id] ?? '#' . $id, $path)),
                'parent_id' => (int) $category->getParentId(),
                'level' => (int) $category->getLevel(),
                'active' => (bool) $category->getIsActive(),
                'products' => (int) $category->getProductCount(),
            ];
        }

        return ['categories' => $categories];
    }
}
