<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Checkbox;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;
use Zero\AiAssistant\Model\Tool\Sales\Orders;
use Zero\Checkbox\Model\Api;
use Zero\Checkbox\Model\Receipts;

/**
 * fiscal_receipts: Checkbox receipts (фіскальні чеки) of an order, or the
 * ones still waiting or failed.
 */
class FiscalReceipts extends ReadTool
{
    public function __construct(
        private readonly Orders $orders,
        private readonly ResourceConnection $resource,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function receipts(): Receipts
    {
        return $this->objectManager->get(Receipts::class);
    }

    public function name(): string
    {
        return 'fiscal_receipts';
    }

    public function description(): string
    {
        return 'Fiscal receipts (фіскальні чеки, Checkbox ПРРО): an order\'s sale and return receipts (by order number), or without one the receipts not done yet (status pending, sent or error) across orders. Each: type, status, fiscal code, attempts, the last error, the receipt\'s public link when done. A receipt is made by itself when an invoice is paid or a credit memo refunded; send_fiscal_receipts retries.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'number' => ['type' => 'string', 'description' => 'Order number'],
            'limit' => ['type' => 'integer', 'default' => 20],
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
        if (!empty($input['number'])) {
            $order = $this->orders->get((string) $input['number']);
            $rows = $this->receipts()->forOrder((int) $order->getId());
            foreach ($rows as &$row) {
                $row['order'] = $order->getIncrementId();
            }
            unset($row);
        } else {
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchAll($connection->select()
                ->from(['r' => $this->resource->getTableName('zero_checkbox_receipt')])
                ->join(['o' => $this->resource->getTableName('sales_order')], 'o.entity_id = r.order_id', ['order' => 'increment_id'])
                ->where('r.status <> ?', Receipts::DONE)
                ->order('r.created_at DESC')
                ->limit(max(1, min(50, (int) ($input['limit'] ?? 20)))));
        }

        return ['receipts' => array_map(static fn (array $r) => [
            'order' => $r['order'],
            'type' => $r['type'],
            'status' => $r['status'],
            'fiscal_code' => $r['fiscal_code'] ?: null,
            'attempts' => (int) $r['attempts'],
            'error' => $r['error'] ? mb_substr((string) $r['error'], 0, 300) : null,
            'created_at' => $r['created_at'],
            'link' => $r['status'] === Receipts::DONE ? Api::receiptUrl((string) $r['receipt_id']) : null,
        ], $rows)];
    }
}
