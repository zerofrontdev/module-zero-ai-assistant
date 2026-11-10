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
use Zero\AiAssistant\Model\Tool\ReadTool;
use Zero\AiAssistant\Model\Tool\Sales\Orders;
use Zero\NovaPoshta\Model\Label;
use Zero\NovaPoshta\Model\Waybills;

/**
 * track_waybill: where an order's parcel is (or any document number's).
 */
class TrackWaybill extends ReadTool
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
        return 'track_waybill';
    }

    public function description(): string
    {
        return 'Where a Nova Poshta parcel is: by order number (its document, the recipient\'s phone for the details, links to print the label again) or by a document number (ТТН). Returns status, branch, scheduled delivery, delivered at, cash on delivery, storage until.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'number' => ['type' => 'string', 'description' => 'Order number'],
            'document_number' => ['type' => 'string', 'description' => 'A ТТН, instead of an order'],
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
        if (!empty($input['document_number'])) {
            $number = (string) preg_replace('/\D+/', '', (string) $input['document_number']);
            if (strlen($number) < 11) {
                throw new LocalizedException(__('A Nova Poshta document number has 14 digits.'));
            }

            return $this->waybills()->track($number);
        }
        $order = $this->orders->get((string) ($input['number'] ?? ''));
        $row = $this->waybills()->forOrder((int) $order->getId());
        if (empty($row['document_number'])) {
            return ['order' => $order->getIncrementId(), 'document_number' => null, 'note' => 'No Nova Poshta document yet: create_waybill makes one.'];
        }
        $number = (string) $row['document_number'];

        return ['order' => $order->getIncrementId()]
            + $this->waybills()->track($number, (string) $order->getShippingAddress()?->getTelephone(), (int) $order->getStoreId())
            + [
                'label_100x100' => $this->label()->link((int) $order->getId(), (int) $order->getStoreId(), $number, 'marking'),
                'label_a4' => $this->label()->link((int) $order->getId(), (int) $order->getStoreId(), $number, 'document'),
            ];
    }
}
