<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Sales;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\OrderRepository;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;

/**
 * What the admin does to an order, one tool per action (etc/di.xml): invoice,
 * ship, comment, hold, unhold, cancel. Each needs the admin's own resource
 * for it and is shown to them to confirm first.
 */
class OrderAction implements ToolInterface
{
    private const ACTIONS = [
        'invoice' => ['Magento_Sales::invoice', 'Invoice the order: all items not invoiced yet. capture "online" takes the money through the payment method (only if it can), "offline" records it as paid elsewhere (cash on delivery, bank transfer).'],
        'ship' => ['Magento_Sales::ship', 'Ship the order: all items not shipped yet, optionally with a tracking number (e.g. a Nova Poshta TTN) and the carrier\'s name. notify emails the customer.'],
        'comment' => ['Magento_Sales::comment', 'Add a comment to the order\'s history. notify emails it to the customer (also shows it in their account).'],
        'hold' => ['Magento_Sales::hold', 'Put the order on hold.'],
        'unhold' => ['Magento_Sales::unhold', 'Release the order from hold.'],
        'cancel' => ['Magento_Sales::cancel', 'Cancel the order (only one not invoiced or shipped yet).'],
    ];

    /**
     * What applies when the call leaves it out (the schema's defaults): shown on the card and used by run().
     */
    private const DEFAULTS = [
        'invoice' => ['capture' => 'offline'],
        'ship' => ['notify' => true],
        'comment' => ['notify' => false],
    ];

    public function __construct(
        private readonly Orders $orders,
        private readonly OrderManagementInterface $orderManagement,
        private readonly InvoiceOrderInterface $invoiceOrder,
        private readonly ShipOrderInterface $shipOrder,
        private readonly ShipmentTrackCreationInterfaceFactory $trackFactory,
        private readonly OrderRepository $orderRepository,
        private readonly OrderCommentSender $commentSender,
        private readonly string $action = 'comment'
    ) {
    }

    public function name(): string
    {
        return $this->action . '_order';
    }

    public function description(): string
    {
        return self::ACTIONS[$this->action][1] . ' By the order\'s number. Shown to the admin to confirm first.';
    }

    public function schema(): array
    {
        $properties = ['number' => ['type' => 'string']];
        $required = ['number'];
        if ($this->action === 'invoice') {
            $properties['capture'] = ['type' => 'string', 'enum' => ['online', 'offline'], 'default' => 'offline'];
        } elseif ($this->action === 'ship') {
            $properties += [
                'tracking_number' => ['type' => 'string'],
                'carrier' => ['type' => 'string', 'description' => 'Carrier name, e.g. Нова пошта'],
                'notify' => ['type' => 'boolean', 'default' => true],
            ];
        } elseif ($this->action === 'comment') {
            $properties += ['comment' => ['type' => 'string'], 'notify' => ['type' => 'boolean', 'default' => false]];
            $required[] = 'comment';
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }

    public function resource(): string
    {
        return self::ACTIONS[$this->action][0];
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
        $input = $this->effective($input);
        $order = $this->orders->get((string) ($input['number'] ?? ''));
        $allowed = match ($this->action) {
            'invoice' => $order->canInvoice(),
            'ship' => $order->canShip(),
            'cancel' => $order->canCancel(),
            'hold' => $order->canHold(),
            'unhold' => $order->canUnhold(),
            default => true,
        };
        if (!$allowed) {
            throw new LocalizedException(__('Order #%1 can\'t be %2 now (status: %3).', $order->getIncrementId(), $this->action, $order->getStatusLabel()));
        }
        if ($this->action === 'invoice' && $input['capture'] === 'online' && !$order->getPayment()->canCapture()) {
            throw new LocalizedException(__('The payment method of order #%1 can\'t capture online: invoice it offline.', $order->getIncrementId()));
        }
        $lines = [
            (string) __('Customer') => $this->orders->summary($order)['customer'],
            (string) __('Total') => $this->orders->money($order, (float) $order->getGrandTotal()),
            (string) __('Status') => (string) $order->getStatusLabel(),
        ];
        foreach ($input as $field => $value) {
            if ($field !== 'number') {
                $lines[$field] = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
            }
        }
        $titles = [
            'invoice' => __('Invoice order #%1', $order->getIncrementId()),
            'ship' => __('Ship order #%1', $order->getIncrementId()),
            'comment' => __('Comment on order #%1', $order->getIncrementId()),
            'hold' => __('Hold order #%1', $order->getIncrementId()),
            'unhold' => __('Release order #%1', $order->getIncrementId()),
            'cancel' => __('Cancel order #%1', $order->getIncrementId()),
        ];

        return ['title' => (string) $titles[$this->action], 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        $input = $this->effective($input);
        $this->preview($input, $context);
        $order = $this->orders->get((string) $input['number']);
        $id = (int) $order->getId();
        switch ($this->action) {
            case 'invoice':
                $invoiceId = $this->invoiceOrder->execute($id, $input['capture'] === 'online');

                return ['invoice_id' => (int) $invoiceId, 'status' => $this->status($id)];
            case 'ship':
                $tracks = [];
                if (!empty($input['tracking_number'])) {
                    $tracks[] = $this->trackFactory->create()
                        ->setTrackNumber((string) $input['tracking_number'])
                        ->setTitle((string) ($input['carrier'] ?? __('Tracking number')))
                        ->setCarrierCode('custom');
                }
                $shipmentId = $this->shipOrder->execute($id, [], (bool) $input['notify'], false, null, $tracks);

                return ['shipment_id' => (int) $shipmentId, 'status' => $this->status($id)];
            case 'comment':
                $notify = (bool) $input['notify'];
                $order->addCommentToStatusHistory((string) $input['comment'])
                    ->setIsCustomerNotified($notify)
                    ->setIsVisibleOnFront($notify);
                $this->orderRepository->save($order);
                if ($notify) {
                    $this->commentSender->send($order, true, (string) $input['comment']);
                }

                return ['commented' => true];
            case 'hold':
                $this->orderManagement->hold($id);

                return ['status' => $this->status($id)];
            case 'unhold':
                $this->orderManagement->unHold($id);

                return ['status' => $this->status($id)];
            case 'cancel':
                $this->orderManagement->cancel($id);

                return ['status' => $this->status($id)];
        }

        throw new LocalizedException(__('Unknown order action %1.', $this->action));
    }

    /**
     * The input with this action's defaults for what it leaves out (or gives as null).
     */
    private function effective(array $input): array
    {
        return array_filter($input, static fn ($value) => $value !== null) + (self::DEFAULTS[$this->action] ?? []);
    }

    private function status(int $orderId): string
    {
        return (string) $this->orderRepository->get($orderId)->getStatusLabel();
    }
}
