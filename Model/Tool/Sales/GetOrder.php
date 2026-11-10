<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Sales;

use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;

class GetOrder extends ReadTool
{
    public function __construct(private readonly Orders $orders)
    {
    }

    public function name(): string
    {
        return 'get_order';
    }

    public function description(): string
    {
        return 'One order by its number: items with ordered/invoiced/shipped/refunded quantities, totals, shipping, address, payment, the latest comments, and what can be done with it now (invoice, ship, cancel, hold, unhold).';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['number' => ['type' => 'string']], 'required' => ['number']];
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
        $order = $this->orders->get((string) $input['number']);
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'price' => $this->orders->money($order, (float) $item->getPriceInclTax()),
                'ordered' => (float) $item->getQtyOrdered(),
                'invoiced' => (float) $item->getQtyInvoiced(),
                'shipped' => (float) $item->getQtyShipped(),
                'refunded' => (float) $item->getQtyRefunded(),
            ];
        }
        $address = $order->getShippingAddress() ?: $order->getBillingAddress();
        $comments = [];
        foreach (array_slice($order->getStatusHistories(), 0, 5) as $history) {
            $comments[] = ['date' => $history->getCreatedAt(), 'comment' => $this->plain($history->getComment(), 300)];
        }

        return $this->orders->summary($order) + [
            'state' => $order->getState(),
            'subtotal' => $this->orders->money($order, (float) $order->getSubtotalInclTax()),
            'shipping' => $order->getShippingDescription() . ' ' . $this->orders->money($order, (float) $order->getShippingInclTax()),
            'discount' => $this->orders->money($order, (float) $order->getDiscountAmount()),
            'paid' => $this->orders->money($order, (float) $order->getTotalPaid()),
            'refunded' => $this->orders->money($order, (float) $order->getTotalRefunded()),
            'address' => $address ? implode(', ', array_filter([
                $address->getName(), implode(' ', (array) $address->getStreet()), $address->getCity(),
                $address->getPostcode(), $address->getCountryId(), $address->getTelephone(),
            ])) : null,
            'items' => $items,
            'comments' => $comments,
            'can' => [
                'invoice' => $order->canInvoice(),
                'ship' => $order->canShip(),
                'cancel' => $order->canCancel(),
                'hold' => $order->canHold(),
                'unhold' => $order->canUnhold(),
            ],
        ];
    }
}
