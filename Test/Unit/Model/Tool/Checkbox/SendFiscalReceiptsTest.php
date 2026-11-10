<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Tool\Checkbox;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Tool\Checkbox\SendFiscalReceipts;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\Sales\Orders;
use Zero\Checkbox\Model\Fiscalizer;
use Zero\Checkbox\Model\Receipts;

/**
 * B9-F1: the failed receipts go back to pending through the Fiscalizer
 * (retryErrors: under each receipt's lock, retry time and notice cleared),
 * never reset here directly; waiting ones keep their attempts and are sent
 * now; `busy` counts the failed ones another process held.
 */
#[AllowMockObjectsWithoutExpectations]
class SendFiscalReceiptsTest extends TestCase
{
    private const ORDER_ID = 42;

    /** @var array<string, array> the order's receipt rows by receipt id */
    private array $rows = [];
    /** @var list<string> receipt ids sent, in order */
    private array $sent = [];
    private Fiscalizer&MockObject $fiscalizer;
    private Receipts&MockObject $receipts;
    /** @var list<Invoice> */
    private array $invoices = [];

    protected function setUp(): void
    {
        $this->rows = [];
        $this->sent = [];
        $this->invoices = [];
        $this->fiscalizer = $this->createMock(Fiscalizer::class);
        $this->fiscalizer->method('send')->willReturnCallback(function (array $row) {
            $this->sent[] = $row['receipt_id'];
            $this->rows[$row['receipt_id']]['status'] = Receipts::DONE;

            return true;
        });
        $this->receipts = $this->createMock(Receipts::class);
        $this->receipts->method('forOrder')->willReturnCallback(
            fn (int $orderId) => $orderId === self::ORDER_ID ? array_values($this->rows) : []
        );
    }

    public function testFailedReceiptsGoBackThroughTheFiscalizerNeverResetHere(): void
    {
        $this->row('r-err', Receipts::ERROR, 'Shift is closed', 4);
        $this->row('r-wait', Receipts::PENDING, null, 2);
        $this->row('r-done', Receipts::DONE);
        $this->fiscalizer->expects($this->once())->method('retryErrors')->with(self::ORDER_ID)
            ->willReturnCallback(function () {
                $this->rows['r-err'] = ['status' => Receipts::PENDING, 'error' => null, 'attempts' => 0] + $this->rows['r-err'];

                return ['reset' => 1, 'busy' => 0];
            });
        $this->receipts->expects($this->never())->method('update');
        $this->receipts->expects($this->never())->method('reset');

        $result = $this->tool()->run(['number' => '000000042'], $this->context());

        self::assertSame(['r-err', 'r-wait'], $this->sent, 'the reset one and the waiting one are sent now, the done one not');
        self::assertSame(2, $this->rows['r-wait']['attempts'], 'a waiting receipt keeps its attempts');
        self::assertSame('000000042', $result['order']);
        self::assertSame(0, $result['made']);
        self::assertSame(0, $result['busy']);
        self::assertSame(
            [Receipts::DONE, Receipts::DONE, Receipts::DONE],
            array_column($result['receipts'], 'status')
        );
        self::assertSame('https://check.checkbox.ua/r-err', $result['receipts'][0]['link']);
    }

    public function testAFailedReceiptHeldByAnotherProcessIsCountedBusyAndNotSent(): void
    {
        $this->row('r-err', Receipts::ERROR, str_repeat('x', 400));
        $this->fiscalizer->expects($this->once())->method('retryErrors')->with(self::ORDER_ID)
            ->willReturn(['reset' => 0, 'busy' => 1]);

        $result = $this->tool()->run(['number' => '000000042'], $this->context());

        self::assertSame(1, $result['busy']);
        self::assertSame([], $this->sent, 'an error row left as it was is not sent');
        self::assertSame(Receipts::ERROR, $result['receipts'][0]['status']);
        self::assertSame(300, mb_strlen($result['receipts'][0]['error']), 'the error is cut to 300 characters');
        self::assertNull($result['receipts'][0]['link']);
    }

    public function testOnlyWaitingOrSentReceiptsStillAskTheFiscalizerToRetry(): void
    {
        // A pending or sent row is "not done": retryErrors runs (it finds no
        // error and changes nothing) and the row is sent as it is.
        $this->row('r-sent', Receipts::SENT, null, 3);
        $this->fiscalizer->expects($this->once())->method('retryErrors')->with(self::ORDER_ID)
            ->willReturn(['reset' => 0, 'busy' => 0]);
        $this->receipts->expects($this->never())->method('update');

        $result = $this->tool()->run(['number' => '000000042'], $this->context());

        self::assertSame(['r-sent'], $this->sent);
        self::assertSame(3, $this->rows['r-sent']['attempts']);
        self::assertSame(0, $result['busy']);
    }

    public function testNothingToRetryDoesNotCallRetryErrorsAndMakesTheMissingReceipt(): void
    {
        $this->row('r-done', Receipts::DONE);
        $this->invoice(9, Invoice::STATE_PAID);
        $this->fiscalizer->expects($this->never())->method('retryErrors');
        $this->fiscalizer->expects($this->once())->method('queueInvoice')
            ->with($this->identicalTo($this->invoices[0]))
            ->willReturnCallback(function () {
                $this->row('r-new', Receipts::PENDING);

                return true;
            });

        $result = $this->tool()->run(['number' => '000000042'], $this->context());

        self::assertSame(1, $result['made']);
        self::assertSame(0, $result['busy']);
        self::assertSame(['r-new'], $this->sent);
    }

    public function testThePreviewRefusesAnOrderWithNothingToSend(): void
    {
        $this->row('r-done', Receipts::DONE);
        $this->invoice(9, Invoice::STATE_OPEN);
        $this->fiscalizer->expects($this->never())->method('retryErrors');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Order #000000042 has nothing to send');
        $this->tool()->preview(['number' => '000000042'], $this->context());
    }

    public function testTheDescriptionSaysWaitingReceiptsAreSentNotRestarted(): void
    {
        $description = $this->tool()->description();

        self::assertStringContainsString('the failed ones tried again from the start', $description);
        self::assertStringContainsString('the waiting ones sent now', $description);
    }

    // ---------------------------------------------------------------- harness

    private function row(string $id, string $status, ?string $error = null, int $attempts = 0): void
    {
        $this->rows[$id] = [
            'receipt_id' => $id, 'order_id' => (string) self::ORDER_ID, 'type' => Receipts::SELL,
            'entity_id' => (string) (count($this->rows) + 1), 'status' => $status, 'error' => $error,
            'attempts' => $attempts, 'fiscal_code' => null,
        ];
    }

    private function invoice(int $id, int $state): void
    {
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getId')->willReturn($id);
        $invoice->method('getState')->willReturn($state);
        $invoice->method('getIncrementId')->willReturn('00000000' . $id);
        $this->invoices[] = $invoice;
    }

    private function tool(): SendFiscalReceipts
    {
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn((string) self::ORDER_ID);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getInvoiceCollection')->willReturnCallback(fn () => $this->invoices);
        $order->method('getCreditmemosCollection')->willReturn([]);
        $orders = $this->createStub(Orders::class);
        $orders->method('get')->willReturn($order);

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn (string $class) => match ($class) {
            Receipts::class => $this->receipts,
            Fiscalizer::class => $this->fiscalizer,
        });

        return new SendFiscalReceipts($orders, $objectManager);
    }

    private function context(): Context
    {
        return new Context(10, 5, $this->createStub(Conversations::class));
    }
}
