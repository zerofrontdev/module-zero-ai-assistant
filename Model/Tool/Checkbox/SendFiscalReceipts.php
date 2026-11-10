<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Checkbox;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\Sales\Orders;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\Checkbox\Model\Api;
use Zero\Checkbox\Model\Fiscalizer;
use Zero\Checkbox\Model\Receipts;

/**
 * send_fiscal_receipts: an order's receipts sent now: the waiting and the
 * failed ones again, and the missing ones made first (a paid invoice or a
 * refunded credit memo without a receipt).
 */
class SendFiscalReceipts implements ToolInterface
{
    public function __construct(
        private readonly Orders $orders,
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

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function fiscalizer(): Fiscalizer
    {
        return $this->objectManager->get(Fiscalizer::class);
    }

    public function name(): string
    {
        return 'send_fiscal_receipts';
    }

    public function description(): string
    {
        return 'Send an order\'s fiscal receipts to Checkbox now: the failed ones tried again from the start, the waiting ones sent now, and first the missing ones made (a paid invoice without a sale receipt, a refunded credit memo without a return receipt). Only for orders in UAH with a payment method Checkbox isn\'t set to skip. Shown to the admin to confirm first.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['number' => ['type' => 'string', 'description' => 'Order number']], 'required' => ['number']];
    }

    public function resource(): string
    {
        return 'Magento_Sales::invoice';
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
        [$retry, $missing] = $this->plan($order);
        if (!$retry && !$missing) {
            throw new LocalizedException(__('Order #%1 has nothing to send: every receipt is done, or nothing is paid or refunded yet.', $order->getIncrementId()));
        }
        $lines = [];
        foreach ($retry as $row) {
            $lines[$row['type'] . ' (' . $row['status'] . ')'] = (string) __('send again') . ($row['error'] ? ': ' . mb_substr((string) $row['error'], 0, 120) : '');
        }
        foreach ($missing as [$kind, $document]) {
            $lines[$kind . ' #' . $document->getIncrementId()] = (string) __('make the receipt and send it');
        }

        return ['title' => (string) __('Fiscal receipts of order #%1', $order->getIncrementId()), 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        $order = $this->orders->get((string) ($input['number'] ?? ''));
        [$retry, $missing] = $this->plan($order);
        // The failed ones back to pending the Fiscalizer's way (under each
        // receipt's lock, its retry time and notice cleared); waiting ones
        // keep their attempts and are sent below as they are.
        $busy = 0;
        if ($retry) {
            $busy = $this->fiscalizer()->retryErrors((int) $order->getId())['busy'];
        }
        $queued = 0;
        foreach ($missing as [$kind, $document]) {
            $queued += (int) ($kind === 'invoice' ? $this->fiscalizer()->queueInvoice($document) : $this->fiscalizer()->queueCreditmemo($document));
        }
        $result = [];
        foreach ($this->receipts()->forOrder((int) $order->getId()) as $row) {
            if ($row['status'] === Receipts::PENDING || $row['status'] === Receipts::SENT) {
                $this->fiscalizer()->send($row);
            }
        }
        foreach ($this->receipts()->forOrder((int) $order->getId()) as $row) {
            $result[] = [
                'type' => $row['type'],
                'status' => $row['status'],
                'fiscal_code' => $row['fiscal_code'] ?: null,
                'error' => $row['error'] ? mb_substr((string) $row['error'], 0, 300) : null,
                'link' => $row['status'] === Receipts::DONE ? Api::receiptUrl((string) $row['receipt_id']) : null,
            ];
        }

        // busy: failed receipts another process held at that moment, left as they were.
        return ['order' => $order->getIncrementId(), 'made' => $queued, 'busy' => $busy, 'receipts' => $result];
    }

    /**
     * @return array{0: array[], 1: array<int, array{0: string, 1: Invoice|Creditmemo}>}
     */
    private function plan(Order $order): array
    {
        $rows = $this->receipts()->forOrder((int) $order->getId());
        $retry = array_values(array_filter($rows, static fn (array $r) => $r['status'] !== Receipts::DONE));
        $covered = [];
        foreach ($rows as $row) {
            $covered[$row['type'] . ':' . $row['entity_id']] = true;
        }
        $missing = [];
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ((int) $invoice->getState() === Invoice::STATE_PAID && !isset($covered[Receipts::SELL . ':' . $invoice->getId()])) {
                $missing[] = ['invoice', $invoice];
            }
        }
        foreach ($order->getCreditmemosCollection() ?: [] as $creditmemo) {
            if ((int) $creditmemo->getState() === Creditmemo::STATE_REFUNDED && !isset($covered[Receipts::RETURN . ':' . $creditmemo->getId()])) {
                $missing[] = ['credit memo', $creditmemo];
            }
        }

        return [$retry, $missing];
    }
}
