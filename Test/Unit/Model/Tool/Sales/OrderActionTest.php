<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Tool\Sales;

use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\OrderRepository;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\Sales\OrderAction;
use Zero\AiAssistant\Model\Tool\Sales\Orders;

/**
 * The order card shows the notify and capture that apply when Claude leaves them out, and run() uses the same.
 */
class OrderActionTest extends TestCase
{
    private ShipOrderInterface $shipOrder;
    private InvoiceOrderInterface $invoiceOrder;
    private OrderCommentSender $commentSender;
    private ?Order\Status\History $history = null;

    protected function setUp(): void
    {
        $this->shipOrder = $this->createStub(ShipOrderInterface::class);
        $this->invoiceOrder = $this->createStub(InvoiceOrderInterface::class);
        $this->commentSender = $this->createStub(OrderCommentSender::class);
    }

    private function action(string $action): OrderAction
    {
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn('42');
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getStatusLabel')->willReturn('Processing');
        $order->method('getGrandTotal')->willReturn('59.00');
        $order->method('canShip')->willReturn(true);
        $order->method('canInvoice')->willReturn(true);
        $order->method('canHold')->willReturn(true);
        $history = $this->history;
        if ($history === null) {
            $history = $this->createStub(Order\Status\History::class);
            $history->method('setIsCustomerNotified')->willReturnSelf();
            $history->method('setIsVisibleOnFront')->willReturnSelf();
        }
        $order->method('addCommentToStatusHistory')->willReturn($history);
        $orders = $this->createStub(Orders::class);
        $orders->method('get')->willReturn($order);
        $orders->method('summary')->willReturn(['customer' => 'Veronica Costello']);
        $orders->method('money')->willReturn('$59.00');
        $repository = $this->createStub(OrderRepository::class);
        $repository->method('get')->willReturn($order);

        return new OrderAction(
            $orders,
            $this->createStub(OrderManagementInterface::class),
            $this->invoiceOrder,
            $this->shipOrder,
            $this->createStub(ShipmentTrackCreationInterfaceFactory::class),
            $repository,
            $this->commentSender,
            $action
        );
    }

    private function context(): Context
    {
        return new Context(10, 5, $this->createStub(Conversations::class));
    }

    public function testShipPreviewShowsNotifyYesByDefault(): void
    {
        $lines = $this->action('ship')->preview(['number' => '000000042'], $this->context())['lines'];

        $this->assertSame('yes', $lines['notify']);
        $this->assertSame('Veronica Costello', $lines['Customer']);
    }

    public function testANullNotifyIsTheDefaultToo(): void
    {
        $lines = $this->action('ship')->preview(['number' => '000000042', 'notify' => null], $this->context())['lines'];

        $this->assertSame('yes', $lines['notify']);
    }

    public function testCommentPreviewShowsNotifyNoByDefault(): void
    {
        $lines = $this->action('comment')->preview(['number' => '000000042', 'comment' => 'Packed'], $this->context())['lines'];

        $this->assertSame('no', $lines['notify']);
        $this->assertSame('Packed', $lines['comment']);
    }

    public function testInvoicePreviewShowsCaptureOffline(): void
    {
        $lines = $this->action('invoice')->preview(['number' => '000000042'], $this->context())['lines'];

        $this->assertSame('offline', $lines['capture']);
    }

    public function testAGivenNotifyIsShownAsGiven(): void
    {
        $lines = $this->action('ship')->preview(['number' => '000000042', 'notify' => false], $this->context())['lines'];

        $this->assertSame('no', $lines['notify']);
    }

    public function testHoldHasNoNotifyLine(): void
    {
        $order = $this->action('hold');
        $lines = $order->preview(['number' => '000000042'], $this->context());

        $this->assertArrayNotHasKey('notify', $lines['lines']);
        $this->assertArrayNotHasKey('capture', $lines['lines']);
    }

    public function testShipRunNotifiesByDefaultAsTheCardSaid(): void
    {
        $ship = $this->createMock(ShipOrderInterface::class);
        $ship->expects($this->once())->method('execute')->with(42, [], true, false, null, [])->willReturn(7);
        $this->shipOrder = $ship;

        $this->assertSame(['shipment_id' => 7, 'status' => 'Processing'], $this->action('ship')->run(['number' => '000000042'], $this->context()));
    }

    public function testInvoiceRunCapturesOfflineByDefault(): void
    {
        $invoice = $this->createMock(InvoiceOrderInterface::class);
        $invoice->expects($this->once())->method('execute')->with(42, false)->willReturn(3);
        $this->invoiceOrder = $invoice;

        $this->assertSame(['invoice_id' => 3, 'status' => 'Processing'], $this->action('invoice')->run(['number' => '000000042'], $this->context()));
    }

    public function testShipRunUsesAGivenNotifyOverTheDefault(): void
    {
        $ship = $this->createMock(ShipOrderInterface::class);
        $ship->expects($this->once())->method('execute')->with(42, [], false, false, null, [])->willReturn(8);
        $this->shipOrder = $ship;

        $this->assertSame(['shipment_id' => 8, 'status' => 'Processing'], $this->action('ship')->run(['number' => '000000042', 'notify' => false], $this->context()));
    }

    public function testCommentRunWithNotifyEmailsAndShowsItInTheAccount(): void
    {
        $this->history = $this->historyExpecting(true);
        $sender = $this->createMock(OrderCommentSender::class);
        $sender->expects($this->once())->method('send')->with($this->isInstanceOf(Order::class), true, 'Packed');
        $this->commentSender = $sender;

        $this->assertSame(['commented' => true], $this->action('comment')->run(['number' => '000000042', 'comment' => 'Packed', 'notify' => true], $this->context()));
    }

    private function historyExpecting(bool $notify): Order\Status\History
    {
        $history = $this->createMock(Order\Status\History::class);
        $history->expects($this->once())->method('setIsCustomerNotified')->with($notify)->willReturnSelf();
        $history->expects($this->once())->method('setIsVisibleOnFront')->with($notify)->willReturnSelf();
        return $history;
    }

    public function testCommentRunDoesNotEmailByDefault(): void
    {
        $this->history = $this->historyExpecting(false);
        $sender = $this->createMock(OrderCommentSender::class);
        $sender->expects($this->never())->method('send');
        $this->commentSender = $sender;

        $this->assertSame(['commented' => true], $this->action('comment')->run(['number' => '000000042', 'comment' => 'Packed'], $this->context()));
    }
}
