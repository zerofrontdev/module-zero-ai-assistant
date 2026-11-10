<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Sales;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;

/**
 * Orders by their number, and their money as the admin reads it.
 */
class Orders
{
    public function __construct(
        private readonly OrderFactory $orderFactory,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function get(string $number): Order
    {
        $order = $this->orderFactory->create()->loadByIncrementId(trim($number, " #\t"));
        if (!$order->getId()) {
            throw new LocalizedException(__('No order #%1.', $number));
        }

        return $order;
    }

    public function money(Order $order, float $amount): string
    {
        return $this->priceCurrency->format($amount, false, 2, null, $order->getOrderCurrencyCode());
    }

    public function summary(Order $order): array
    {
        return [
            'number' => $order->getIncrementId(),
            'date' => $order->getCreatedAt(),
            'status' => $order->getStatusLabel() ?: $order->getStatus(),
            'customer' => trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname()),
            'email' => $order->getCustomerEmail(),
            'total' => $this->money($order, (float) $order->getGrandTotal()),
            'items' => (int) $order->getTotalItemCount(),
            'payment' => (string) $order->getPayment()?->getMethodInstance()->getTitle(),
            'store' => $order->getStore()->getName(),
        ];
    }
}
