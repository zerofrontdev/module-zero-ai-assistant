<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Sales;

use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;

class ListOrders extends ReadTool
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly Orders $orders
    ) {
    }

    public function name(): string
    {
        return 'list_orders';
    }

    public function description(): string
    {
        return 'The latest orders, filtered: status (pending, processing, complete, canceled, holded, pending_payment…), dates (YYYY-MM-DD), the customer\'s email or name. Number, date, status, customer, total, items, payment, store. Show them to the admin as a table.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string'],
            'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            'to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            'customer' => ['type' => 'string', 'description' => 'Email or (part of) the name'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
        ]];
    }

    public function resource(): string
    {
        return 'Magento_Sales::sales_order';
    }

    public function area(): string
    {
        return 'sales';
    }

    public function run(array $input, Context $context): array
    {
        $collection = $this->collectionFactory->create()
            ->setOrder('created_at', 'DESC')
            ->setPageSize(min(50, max(1, (int) ($input['limit'] ?? 10))));
        if (!empty($input['status'])) {
            $collection->addFieldToFilter('status', (string) $input['status']);
        }
        if (!empty($input['from'])) {
            $collection->addFieldToFilter('created_at', ['gteq' => $input['from'] . ' 00:00:00']);
        }
        if (!empty($input['to'])) {
            $collection->addFieldToFilter('created_at', ['lteq' => $input['to'] . ' 23:59:59']);
        }
        if (!empty($input['customer'])) {
            $like = '%' . $input['customer'] . '%';
            $collection->addFieldToFilter(
                ['customer_email', 'customer_firstname', 'customer_lastname'],
                [['like' => $like], ['like' => $like], ['like' => $like]]
            );
        }
        $orders = [];
        foreach ($collection as $order) {
            $orders[] = $this->orders->summary($order);
        }

        return ['count' => count($orders), 'orders' => $orders];
    }
}
