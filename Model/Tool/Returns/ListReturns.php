<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Returns;

use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;
use Zero\Returns\Model\Returns;

/**
 * list_returns: return requests (RMA), the pending ones by default.
 */
class ListReturns extends ReadTool
{
    public function __construct(private readonly ObjectManagerInterface $objectManager)
    {
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function returns(): Returns
    {
        return $this->objectManager->get(Returns::class);
    }

    public function name(): string
    {
        return 'list_returns';
    }

    public function description(): string
    {
        return 'Return requests: status pending (the default), approved, rejected, received, refunded, closed or all; search by customer email, name or order number. Returns id, number, status, order, customer, resolution, lines, qty, created_at. Use get_return for items and messages.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => array_merge(Returns::STATUSES, ['all']), 'default' => 'pending'],
            'search' => ['type' => 'string'],
            'limit' => ['type' => 'integer', 'default' => 20],
        ]];
    }

    public function resource(): string
    {
        return 'Zero_Returns::returns';
    }

    public function area(): string
    {
        return 'returns';
    }

    public function run(array $input, Context $context): array
    {
        $status = (string) ($input['status'] ?? 'pending');
        $rows = $this->returns()->all($status === 'all' ? '' : $status, trim((string) ($input['search'] ?? '')), null, max(1, min(50, (int) ($input['limit'] ?? 20))));

        return ['returns' => array_map(static fn (array $r) => [
            'id' => (int) $r['return_id'],
            'number' => Returns::number($r),
            'status' => $r['status'],
            'order' => $r['order_increment_id'],
            'customer' => $r['customer_name'],
            'email' => $r['customer_email'],
            'resolution' => $r['resolution'] ?? null,
            'lines' => (int) $r['lines'],
            'qty' => (float) $r['qty'],
            'created_at' => $r['created_at'],
        ], $rows)];
    }
}
