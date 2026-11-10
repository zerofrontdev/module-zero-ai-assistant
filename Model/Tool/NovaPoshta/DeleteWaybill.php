<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\NovaPoshta;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\Sales\Orders;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\NovaPoshta\Model\Waybills;

/**
 * delete_waybill: the order's Nova Poshta document deleted (before the
 * parcel is handed over), its tracking line taken off the shipment.
 */
class DeleteWaybill implements ToolInterface
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
    private function waybills(): Waybills
    {
        return $this->objectManager->get(Waybills::class);
    }

    public function name(): string
    {
        return 'delete_waybill';
    }

    public function description(): string
    {
        return 'Delete an order\'s Nova Poshta shipping document, e.g. to make it again with another weight: only while the parcel isn\'t handed over (Nova Poshta refuses otherwise). Its tracking line is taken off the order\'s shipment; the shipment itself stays. Shown to the admin to confirm first.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['number' => ['type' => 'string', 'description' => 'Order number']], 'required' => ['number']];
    }

    public function resource(): string
    {
        return 'Magento_Sales::ship';
    }

    public function area(): string
    {
        return 'sales';
    }

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        $order = $this->orders->get((string) ($input['number'] ?? ''));
        $row = $this->waybills()->forOrder((int) $order->getId());
        if (empty($row['document_number'])) {
            throw new LocalizedException(__('Order #%1 has no shipping document.', $order->getIncrementId()));
        }

        return ['title' => (string) __('Delete Nova Poshta document %1 of order #%2', $row['document_number'], $order->getIncrementId()), 'lines' => [
            (string) __('To') => $row['city'] . ', ' . $row['branch'],
        ]];
    }

    public function run(array $input, Context $context): array
    {
        $order = $this->orders->get((string) ($input['number'] ?? ''));
        $number = $this->waybills()->delete($order);
        $tracks = $this->resource->getConnection()->delete($this->resource->getTableName('sales_shipment_track'), [
            'order_id = ?' => (int) $order->getId(),
            'track_number = ?' => $number,
        ]);

        return ['order' => $order->getIncrementId(), 'deleted' => $number, 'tracking_lines_removed' => $tracks];
    }
}
