<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Ukrposhta;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\Sales\Orders;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\Ukrposhta\Model\Label;
use Zero\Ukrposhta\Model\Shipments;

/**
 * create_ukrposhta_shipment: an Укрпошта shipment for an order to a post
 * office, then the order shipped with its barcode as the tracking number.
 */
class CreateShipment implements ToolInterface
{
    public function __construct(
        private readonly Orders $orders,
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

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function label(): Label
    {
        return $this->objectManager->get(Label::class);
    }

    public function name(): string
    {
        return 'create_ukrposhta_shipment';
    }

    public function description(): string
    {
        return 'Create the Ukrposhta shipment for an order shipped to a Ukrposhta post office: from the store\'s sender to the order\'s post office (W2W), the customer\'s name and phone, cash on delivery (післяплата) for the order\'s amount due when its payment method is set as such. Optional: weight (kg; else the products\' weight or the default), length (cm), description (contents), declared_value, cod and cod_amount, payer (Recipient or Sender), type (STANDARD or EXPRESS). Then, unless ship is false, the order is shipped with the barcode as its tracking (notify emails the customer). Returns the barcode, the delivery cost and links to print the sticker. Shown to the admin to confirm first.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'number' => ['type' => 'string', 'description' => 'Order number'],
            'weight' => ['type' => 'number'],
            'length' => ['type' => 'integer'],
            'description' => ['type' => 'string'],
            'declared_value' => ['type' => 'number'],
            'cod' => ['type' => 'boolean'],
            'cod_amount' => ['type' => 'number'],
            'payer' => ['type' => 'string', 'enum' => ['Recipient', 'Sender']],
            'type' => ['type' => 'string', 'enum' => ['STANDARD', 'EXPRESS']],
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
        $draft = $this->shipments()->draft($order, $input);
        $ship = (bool) ($input['ship'] ?? true);
        if ($ship && !$order->canShip()) {
            throw new LocalizedException(__('Order #%1 can\'t be shipped (already shipped, or not paid yet when payment comes first). Use ship: false for the document only.', $order->getIncrementId()));
        }
        $to = $draft['recipient'];
        $from = $draft['sender'];
        $parcel = $draft['parcel'];

        return [
            'title' => (string) __('Ukrposhta shipment for order #%1', $order->getIncrementId()),
            'lines' => [
                (string) __('Recipient') => $to['first_name'] . ' ' . $to['last_name'] . ', ' . $to['phone'],
                (string) __('To') => $to['postcode'] . ', ' . $to['office'],
                (string) __('From') => $from['name'] . ', ' . $from['postcode'] . ' (' . $from['phone'] . ')',
                (string) __('Parcel') => $parcel['type'] . ', ' . $parcel['weight'] . ' kg, ' . $parcel['length'] . ' cm, ' . $parcel['description'],
                (string) __('Declared value') => $this->orders->money($order, $parcel['declared_value']),
                (string) __('Delivery paid by') => $parcel['payer'] === 'Sender' ? (string) __('the store') : (string) __('the recipient'),
                (string) __('Cash on delivery') => $parcel['cod'] ? $this->orders->money($order, $parcel['cod_amount']) : (string) __('no'),
                (string) __('Then') => $ship ? ((bool) ($input['notify'] ?? true) ? (string) __('ship the order with this barcode; the customer is emailed') : (string) __('ship the order with this barcode')) : (string) __('the document only'),
            ],
        ];
    }

    public function run(array $input, Context $context): array
    {
        $order = $this->orders->get((string) ($input['number'] ?? ''));
        $shipment = $this->shipments()->create($order, $input);
        $shipmentId = null;
        $shipError = null;
        if ((bool) ($input['ship'] ?? true)) {
            try {
                $shipmentId = $this->shipments()->ship($order, $shipment['barcode'], (bool) ($input['notify'] ?? true));
            } catch (\Throwable $e) {
                $shipError = $e->getMessage();
            }
        }

        return [
            'order' => $order->getIncrementId(),
            'barcode' => $shipment['barcode'],
            'delivery_cost' => $shipment['cost'],
            'shipment_id' => $shipmentId,
            'ship_error' => $shipError,
            'sticker_100x100' => $this->label()->link((int) $order->getId(), (int) $order->getStoreId(), $shipment['barcode'], '100x100'),
            'sticker_a4' => $this->label()->link((int) $order->getId(), (int) $order->getStoreId(), $shipment['barcode'], 'A4'),
            'tracking' => Shipments::trackingUrl($shipment['barcode']),
        ];
    }
}
