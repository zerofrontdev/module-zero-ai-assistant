<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Ukrposhta;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\Sales\Orders;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\Ukrposhta\Model\Shipments;

/**
 * delete_ukrposhta_shipment: the order's Укрпошта shipment deleted (before
 * it is handed in), its tracking line taken off the order's shipment.
 */
class DeleteShipment implements ToolInterface
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
    private function shipments(): Shipments
    {
        return $this->objectManager->get(Shipments::class);
    }

    public function name(): string
    {
        return 'delete_ukrposhta_shipment';
    }

    public function description(): string
    {
        return 'Delete an order\'s Ukrposhta shipment, e.g. to make it again with another weight: only while it isn\'t handed in at the post office (Ukrposhta refuses otherwise). Its tracking line is taken off the order\'s shipment; the shipment itself stays. Shown to the admin to confirm first.';
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
        $row = $this->shipments()->forOrder((int) $order->getId());
        if (empty($row['barcode'])) {
            throw new LocalizedException(__('Order #%1 has no Ukrposhta shipment.', $order->getIncrementId()));
        }

        return ['title' => (string) __('Delete Ukrposhta shipment %1 of order #%2', $row['barcode'], $order->getIncrementId()), 'lines' => [
            (string) __('To') => $row['postcode'] . ', ' . $row['office'],
        ]];
    }

    public function run(array $input, Context $context): array
    {
        $order = $this->orders->get((string) ($input['number'] ?? ''));
        $number = $this->shipments()->delete($order);
        $tracks = $this->resource->getConnection()->delete($this->resource->getTableName('sales_shipment_track'), [
            'order_id = ?' => (int) $order->getId(),
            'track_number = ?' => $number,
        ]);

        return ['order' => $order->getIncrementId(), 'deleted' => $number, 'tracking_lines_removed' => $tracks];
    }
}
