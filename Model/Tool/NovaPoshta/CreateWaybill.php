<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\NovaPoshta;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\Sales\Orders;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\NovaPoshta\Model\Label;
use Zero\NovaPoshta\Model\Waybills;

/**
 * create_waybill: a Nova Poshta shipping document (ТТН) for an order to a
 * branch, then the order shipped with it as the tracking number.
 */
class CreateWaybill implements ToolInterface
{
    public function __construct(
        private readonly Orders $orders,
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

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function label(): Label
    {
        return $this->objectManager->get(Label::class);
    }

    public function name(): string
    {
        return 'create_waybill';
    }

    public function description(): string
    {
        return 'Create the Nova Poshta shipping document (ТТН, express waybill) for an order shipped to a Nova Poshta branch: from the store\'s sender branch to the order\'s branch, the customer\'s name and phone, cash on delivery (наложений платіж) for the order\'s amount due when its payment method is set as such. Optional: weight (kg; else the products\' weight or the default), seats, description (contents), declared_value, cod and cod_amount, payer (Recipient or Sender). Then, unless ship is false, the order is shipped with the number as its tracking (notify emails the customer). Returns the number, the delivery cost, the estimated delivery and links to print the label. Shown to the admin to confirm first.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'number' => ['type' => 'string', 'description' => 'Order number'],
            'weight' => ['type' => 'number'],
            'seats' => ['type' => 'integer'],
            'description' => ['type' => 'string'],
            'declared_value' => ['type' => 'number'],
            'cod' => ['type' => 'boolean'],
            'cod_amount' => ['type' => 'number'],
            'payer' => ['type' => 'string', 'enum' => ['Recipient', 'Sender']],
            'ship' => ['type' => 'boolean', 'default' => true],
            'notify' => ['type' => 'boolean', 'default' => true],
        ], 'required' => ['number']];
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
        $draft = $this->waybills()->draft($order, $input);
        $ship = (bool) ($input['ship'] ?? true);
        if ($ship && !$order->canShip()) {
            throw new LocalizedException(__('Order #%1 can\'t be shipped (already shipped, or not paid yet when payment comes first). Use ship: false for the document only.', $order->getIncrementId()));
        }
        $to = $draft['recipient'];
        $from = $draft['sender'];
        $parcel = $draft['parcel'];

        return [
            'title' => (string) __('Nova Poshta document for order #%1', $order->getIncrementId()),
            'lines' => [
                (string) __('Recipient') => $to['first_name'] . ' ' . $to['last_name'] . ', +' . $to['phone'],
                (string) __('To') => $to['city'] . ', ' . $to['branch'],
                (string) __('From') => $from['city'] . ', ' . $from['branch'] . ' (' . $from['contact'] . ', +' . $from['phone'] . ')',
                (string) __('Parcel') => $parcel['weight'] . ' kg, ' . $parcel['seats'] . ' seat(s), ' . $parcel['description'],
                (string) __('Declared value') => $this->orders->money($order, $parcel['declared_value']),
                (string) __('Delivery paid by') => $parcel['payer'] === 'Sender' ? (string) __('the store') : (string) __('the recipient'),
                (string) __('Cash on delivery') => $parcel['cod'] ? $this->orders->money($order, $parcel['cod_amount']) : (string) __('no'),
                (string) __('Then') => $ship ? ((bool) ($input['notify'] ?? true) ? (string) __('ship the order with this number; the customer is emailed') : (string) __('ship the order with this number')) : (string) __('the document only'),
            ],
        ];
    }

    public function run(array $input, Context $context): array
    {
        $order = $this->orders->get((string) ($input['number'] ?? ''));
        $document = $this->waybills()->create($order, $input);
        $shipmentId = null;
        $shipError = null;
        if ((bool) ($input['ship'] ?? true)) {
            try {
                $shipmentId = $this->waybills()->ship($order, $document['number'], (bool) ($input['notify'] ?? true));
            } catch (\Throwable $e) {
                $shipError = $e->getMessage();
            }
        }

        return [
            'order' => $order->getIncrementId(),
            'document_number' => $document['number'],
            'delivery_cost' => $document['cost'],
            'estimated_delivery' => $document['estimated_delivery'],
            'shipment_id' => $shipmentId,
            'ship_error' => $shipError,
            'label_100x100' => $this->label()->link((int) $order->getId(), (int) $order->getStoreId(), $document['number'], 'marking'),
            'label_a4' => $this->label()->link((int) $order->getId(), (int) $order->getStoreId(), $document['number'], 'document'),
            'tracking' => Waybills::trackingUrl($document['number']),
        ];
    }
}
