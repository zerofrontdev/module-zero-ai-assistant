<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\AiAssistant\Model\Agent;
use Zero\AiAssistant\Model\Bulk\Bulk;
use Zero\AiAssistant\Model\Bulk\Jobs;
use Zero\AiAssistant\Model\Bulk\StillWritingException;
use Zero\AiAssistant\Model\Client;
use Zero\AiAssistant\Model\Compactor;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReversibleInterface;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\AiAssistant\Model\Tools;
use Zero\AiAssistant\Model\Uploads;

/**
 * Undo says what it did: a product change put back once (under a lock), a bulk job queued through Bulk::undo, and
 * anything that can't be undone, is undone already or is busy says so instead of claiming success. Confirm and a
 * tool call check the records' own ACL (Tools::authorize) before anything runs.
 */
class AgentTest extends TestCase
{
    private const ROLE_GONE = 'Your role doesn\'t allow this any more.';

    private Conversations $conversations;
    private Tools $tools;
    private Jobs $jobs;
    private Bulk $bulk;
    private LockManagerInterface $locks;
    private LoggerInterface $logger;
    private Client $client;

    protected function setUp(): void
    {
        $this->conversations = $this->createStub(Conversations::class);
        $this->tools = $this->createStub(Tools::class);
        $this->jobs = $this->createStub(Jobs::class);
        $this->bulk = $this->createStub(Bulk::class);
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $locks->method('unlock')->willReturn(true);
        $this->locks = $locks;
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->client = $this->createStub(Client::class);
    }

    private function agent(): Agent
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseCurrencyCode')->willReturn('USD');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $compactor = $this->createStub(Compactor::class);
        $compactor->method('prepare')->willReturn(['messages' => [], 'summary' => null]);

        return new Agent(
            $this->client,
            $this->tools,
            $this->conversations,
            $this->createStub(Uploads::class),
            $compactor,
            $this->createStub(ScopeConfigInterface::class),
            $stores,
            $this->logger,
            $this->jobs,
            $this->bulk,
            $this->locks
        );
    }

    private function action(array $fields = []): array
    {
        return $fields + [
            'action_id' => 7,
            'conversation_id' => 10,
            'user_id' => 5,
            'tool' => 'update_product',
            'status' => 'done',
            'input' => ['sku' => 'MH01', 'price' => 99.0],
            'undo' => ['sku' => 'MH01', 'price' => 45.0],
            'result' => '',
        ];
    }

    private function givenAction(array $action): void
    {
        $this->conversations->method('action')->willReturn($action);
    }

    /**
     * @return ToolInterface&ReversibleInterface&Stub
     */
    private function reversibleTool(): ToolInterface
    {
        $tool = $this->createStubForIntersectionOfInterfaces([ToolInterface::class, ReversibleInterface::class]);
        $tool->method('name')->willReturn('update_product');
        return $tool;
    }

    public function testAConcurrentUndoIsBusy(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects($this->once())->method('lock')->with('zero_ai_undo_7', 0)->willReturn(false);
        $locks->expects($this->never())->method('unlock');
        $this->locks = $locks;
        $conversations = $this->createMock(Conversations::class);
        $conversations->expects($this->never())->method('action');
        $conversations->expects($this->never())->method('finish');
        $this->conversations = $conversations;

        $result = $this->agent()->undo(7, 5);

        $this->assertSame(['status' => 'busy', 'result' => 'Change #7 is being undone already.'], $result);
    }

    public function testUndoRestoresOnceAndReleasesTheLock(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects($this->once())->method('lock')->with('zero_ai_undo_7', 0)->willReturn(true);
        $locks->expects($this->once())->method('unlock')->with('zero_ai_undo_7')->willReturn(true);
        $this->locks = $locks;
        $tool = $this->createMockForIntersectionOfInterfaces([ToolInterface::class, ReversibleInterface::class]);
        $tool->expects($this->once())->method('restore')
            ->with(['sku' => 'MH01', 'price' => 45.0], $this->callback(static fn (Context $c) => $c->actionId === 7 && $c->userId === 5 && $c->conversationId === 10))
            ->willReturn(['id' => 1, 'sku' => 'MH01', 'restored' => 'price']);
        $this->tools->method('get')->willReturnCallback(static fn (string $name) => $name === 'update_product' ? $tool : null);
        $conversations = $this->createMock(Conversations::class);
        $conversations->method('action')->willReturn($this->action());
        $conversations->expects($this->once())->method('finish')->with(7, 'undone', '{"id":1,"sku":"MH01","restored":"price"}');
        $conversations->expects($this->once())->method('addMessage')->with(10, 'user', [['type' => 'text', 'text' => '[The admin undid change #7 (update_product): the previous values are back.]']]);
        $this->conversations = $conversations;

        $result = $this->agent()->undo(7, 5);

        $this->assertSame(['status' => 'undone', 'result' => ['id' => 1, 'sku' => 'MH01', 'restored' => 'price']], $result);
    }

    public function testUndoTwiceSaysAlreadyUndone(): void
    {
        $tool = $this->createMockForIntersectionOfInterfaces([ToolInterface::class, ReversibleInterface::class]);
        $tool->expects($this->never())->method('restore');
        $this->tools->method('get')->willReturn($tool);
        $conversations = $this->createMock(Conversations::class);
        $conversations->method('action')->willReturn($this->action(['status' => 'undone']));
        $conversations->expects($this->never())->method('finish');
        $conversations->expects($this->never())->method('addMessage');
        $this->conversations = $conversations;

        $result = $this->agent()->undo(7, 5);

        $this->assertSame(['status' => 'already_undone', 'result' => 'Change #7 is undone already.'], $result);
    }

    public function testUndoOfAChangeWithNothingKeptIsNotUndoable(): void
    {
        $conversations = $this->createMock(Conversations::class);
        $conversations->method('action')->willReturn($this->action(['tool' => 'set_settings', 'undo' => null]));
        $conversations->expects($this->never())->method('finish');
        $conversations->expects($this->never())->method('addMessage');
        $conversations->expects($this->never())->method('setUndo');
        $this->conversations = $conversations;
        $jobs = $this->createMock(Jobs::class);
        $jobs->expects($this->never())->method('forAction');
        $this->jobs = $jobs;

        $result = $this->agent()->undo(7, 5);

        $this->assertSame('not_undoable', $result['status']);
        $this->assertSame('Change #7 (set_settings) can\'t be undone: nothing was kept to put back. Change it again instead.', $result['result']);
    }

    public function testAChangeNotDoneKeepsItsOwnStatusAndAMissingOneIsMissing(): void
    {
        $this->conversations->method('action')->willReturnOnConsecutiveCalls($this->action(['status' => 'proposed']), null);
        $agent = $this->agent();

        $this->assertSame(['status' => 'proposed', 'result' => ''], $agent->undo(7, 5));
        $this->assertSame(['status' => 'missing', 'result' => ''], $agent->undo(8, 5));
    }

    public function testUndoOfAChangeTheRoleNoLongerAllowsFails(): void
    {
        $this->givenAction($this->action());
        $this->tools->method('get')->willReturn(null);

        $this->assertSame(['status' => 'failed', 'result' => self::ROLE_GONE], $this->agent()->undo(7, 5));
    }

    public function testARestoreThatFailsUnexpectedlyIsLoggedAndTheLockIsReleased(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $locks->expects($this->once())->method('unlock')->with('zero_ai_undo_7');
        $this->locks = $locks;
        $tool = $this->reversibleTool();
        $tool->method('restore')->willThrowException(new \RuntimeException('SQLSTATE[42S02] secret table'));
        $this->tools->method('get')->willReturn($tool);
        $conversations = $this->createMock(Conversations::class);
        $conversations->method('action')->willReturn($this->action());
        $conversations->expects($this->never())->method('finish');
        $this->conversations = $conversations;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('undo of change #7'));
        $this->logger = $logger;

        $result = $this->agent()->undo(7, 5);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('It failed unexpectedly: the details are in var/log/system.log.', $result['result']);
        $this->assertStringNotContainsString('SQLSTATE', $result['result']);
    }

    public function testARestoreRefusedShowsItsOwnMessage(): void
    {
        $tool = $this->reversibleTool();
        $tool->method('restore')->willThrowException(new LocalizedException(__('No product with SKU %1.', 'MH01')));
        $this->tools->method('get')->willReturn($tool);
        $this->givenAction($this->action());

        $this->assertSame(['status' => 'failed', 'result' => 'No product with SKU MH01.'], $this->agent()->undo(7, 5));
    }

    public function testTheLockIsReleasedWhenReadingTheChangeThrows(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $locks->expects($this->once())->method('unlock')->with('zero_ai_undo_7');
        $this->locks = $locks;
        $this->conversations->method('action')->willThrowException(new \RuntimeException('gone away'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gone away');
        $this->agent()->undo(7, 5);
    }

    private function bulkAction(): array
    {
        // A legacy bulk change: nothing in undo, the job carries it.
        return $this->action(['tool' => 'bulk_write_products', 'undo' => null, 'result' => '{"job_id":3}']);
    }

    private function job(array $fields = []): array
    {
        return $fields + ['job_id' => 3, 'action_id' => 7, 'user_id' => 5, 'status' => Jobs::DONE, 'conversation_id' => 10];
    }

    public function testUndoOfABulkChangeUndoesItsJob(): void
    {
        $conversations = $this->createMock(Conversations::class);
        $conversations->method('action')->willReturn($this->bulkAction());
        $conversations->expects($this->never())->method('finish');
        $this->conversations = $conversations;
        $this->jobs->method('forAction')->willReturnCallback(fn (int $id) => $id === 7 ? $this->job() : null);
        $this->tools->method('get')->willReturnCallback(fn (string $name) => $name === 'bulk_write_products' ? $this->createStub(ToolInterface::class) : null);
        $bulk = $this->createMock(Bulk::class);
        $bulk->expects($this->once())->method('undo')->with($this->job())->willReturn(12);
        $this->bulk = $bulk;

        $result = $this->agent()->undo(7, 5);

        $this->assertSame(['status' => 'undoing', 'result' => ['job_id' => 3, 'products' => 12]], $result);
    }

    public function testABulkJobWithNothingWrittenIsUndoneAtOnce(): void
    {
        $this->givenAction($this->bulkAction());
        $this->jobs->method('forAction')->willReturn($this->job(['status' => Jobs::CANCELLED]));
        $this->tools->method('get')->willReturn($this->createStub(ToolInterface::class));
        $this->bulk->method('undo')->willReturn(0);

        $this->assertSame(['status' => 'undone', 'result' => ['job_id' => 3, 'products' => 0]], $this->agent()->undo(7, 5));
    }

    public function testUndoOfAnotherUsersJobIsRefused(): void
    {
        $this->givenAction($this->bulkAction());
        $this->jobs->method('forAction')->willReturn($this->job(['user_id' => 9]));
        $this->tools->method('get')->willReturn($this->createStub(ToolInterface::class));
        $bulk = $this->createMock(Bulk::class);
        $bulk->expects($this->never())->method('undo');
        $this->bulk = $bulk;

        $this->assertSame(['status' => 'failed', 'result' => self::ROLE_GONE], $this->agent()->undo(7, 5));
    }

    public function testUndoOfAJobByARoleWithoutBulkWritingIsRefused(): void
    {
        $this->givenAction($this->bulkAction());
        $this->jobs->method('forAction')->willReturn($this->job());
        $connectorTools = $this->createMock(Tools::class);
        $connectorTools->expects($this->atLeastOnce())->method('get')->with('bulk_write_products')->willReturn(null);
        $bulk = $this->createMock(Bulk::class);
        $bulk->expects($this->never())->method('undo');
        $this->bulk = $bulk;

        // The connection's tools (its role) decide, not the session's.
        $this->tools->method('get')->willReturn($this->createStub(ToolInterface::class));
        $this->assertSame(['status' => 'failed', 'result' => self::ROLE_GONE], $this->agent()->undo(7, 5, $connectorTools));
    }

    public function testAJobUndoneOrBeingUndoneSaysAlreadyUndone(): void
    {
        foreach ([Jobs::UNDOING, Jobs::UNDONE] as $status) {
            $this->setUp();
            $this->givenAction($this->bulkAction());
            $this->jobs->method('forAction')->willReturn($this->job(['status' => $status]));
            $this->tools->method('get')->willReturn($this->createStub(ToolInterface::class));
            $bulk = $this->createMock(Bulk::class);
            $bulk->expects($this->never())->method('undo');
            $this->bulk = $bulk;

            $this->assertSame(
                ['status' => 'already_undone', 'result' => 'Bulk job #3 is undone already, or being undone.'],
                $this->agent()->undo(7, 5),
                $status
            );
        }
    }

    public function testUndoOfABulkJobStillWritingFailsAndSaysSo(): void
    {
        $this->givenAction($this->bulkAction());
        $this->jobs->method('forAction')->willReturn($this->job(['status' => Jobs::RUNNING]));
        $this->tools->method('get')->willReturn($this->createStub(ToolInterface::class));
        $this->bulk->method('undo')->willThrowException(new StillWritingException(__('It is still writing: cancel it first, or wait.')));

        $this->assertSame(
            ['status' => 'failed', 'result' => 'It is still writing: cancel it first, or wait.', 'still_writing' => true],
            $this->agent()->undo(7, 5)
        );
    }

    public function testUndoOfABulkJobTakenByAnotherUndoFailsWithBulksMessage(): void
    {
        $this->givenAction($this->bulkAction());
        $this->jobs->method('forAction')->willReturn($this->job());
        $this->tools->method('get')->willReturn($this->createStub(ToolInterface::class));
        $this->bulk->method('undo')->willThrowException(new LocalizedException(__('It is being undone already.')));

        $result = $this->agent()->undo(7, 5);

        $this->assertSame(['status' => 'failed', 'result' => 'It is being undone already.'], $result);
        $this->assertArrayNotHasKey('still_writing', $result);
    }

    public function testConfirmRefusedByTheRecordsAclRunsNothing(): void
    {
        $input = ['review_ids' => [1, 2], 'status' => 'approved'];
        $message = 'Your role doesn\'t allow moderate_reviews on these records: it needs Magento_Review::reviews_all.';
        $tool = $this->createMockForIntersectionOfInterfaces([ToolInterface::class, ReversibleInterface::class]);
        $tool->expects($this->never())->method('run');
        $tool->expects($this->never())->method('snapshot');
        $tools = $this->createMock(Tools::class);
        $tools->method('get')->with('moderate_reviews')->willReturn($tool);
        $tools->expects($this->once())->method('authorize')->with($tool, $input)->willThrowException(new LocalizedException(__($message)));
        $this->tools = $tools;
        $conversations = $this->createMock(Conversations::class);
        $conversations->method('action')->willReturn($this->action(['tool' => 'moderate_reviews', 'status' => 'proposed', 'input' => $input]));
        $conversations->method('claim')->willReturn(true);
        $conversations->expects($this->never())->method('setUndo');
        $conversations->expects($this->once())->method('finish')->with(7, 'failed', $message);
        $conversations->expects($this->once())->method('addMessage')
            ->with(10, 'user', [['type' => 'text', 'text' => '[The admin confirmed change #7 (moderate_reviews), and it failed: ' . $message . ']']]);
        $this->conversations = $conversations;

        $this->assertSame(['status' => 'failed', 'result' => $message], $this->agent()->confirm(7, 5));
    }

    public function testConfirmChecksTheRecordsBeforeTheSnapshotAndTheRun(): void
    {
        $order = [];
        $tool = $this->reversibleTool();
        $tool->method('snapshot')->willReturnCallback(static function () use (&$order) {
            $order[] = 'snapshot';
            return ['sku' => 'MH01'];
        });
        $tool->method('run')->willReturnCallback(static function () use (&$order) {
            $order[] = 'run';
            return ['ok' => true];
        });
        $tools = $this->createStub(Tools::class);
        $tools->method('get')->willReturn($tool);
        $tools->method('authorize')->willReturnCallback(static function () use (&$order) {
            $order[] = 'authorize';
        });
        $this->conversations->method('action')->willReturn($this->action(['status' => 'proposed']));
        $this->conversations->method('claim')->willReturn(true);

        // The connector's tools are the ones checked when given.
        $result = $this->agent()->confirm(7, 5, $tools);

        $this->assertSame(['status' => 'done', 'result' => ['ok' => true]], $result);
        $this->assertSame(['authorize', 'snapshot', 'run'], $order);
    }

    public function testAToolCallRefusedByTheRecordsAclProposesNothing(): void
    {
        $message = 'Your role doesn\'t allow moderate_reviews on these records: it needs Magento_Review::reviews_all.';
        $input = ['review_ids' => [4], 'status' => 'approved'];
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('changes')->willReturn(true);
        $tool->expects($this->never())->method('preview');
        $tool->expects($this->never())->method('run');
        $tools = $this->createMock(Tools::class);
        $tools->method('get')->willReturn($tool);
        $tools->method('definitions')->willReturn([]);
        $tools->expects($this->once())->method('authorize')->with($tool, $input)->willThrowException(new LocalizedException(__($message)));
        $this->tools = $tools;
        $this->client->method('send')->willReturnOnConsecutiveCalls(
            ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'moderate_reviews', 'input' => $input]]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'I can\'t.']]]
        );
        $messages = [];
        $conversations = $this->createMock(Conversations::class);
        $conversations->method('messages')->willReturn([]);
        $conversations->expects($this->never())->method('propose');
        $conversations->method('addMessage')->willReturnCallback(static function (int $id, string $role, array $content) use (&$messages) {
            $messages[] = [$role, $content];
            return 1;
        });
        $this->conversations = $conversations;

        $this->agent()->send(10, 5, 'Admin', 'Approve review 4', []);

        $this->assertSame(['user', [['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => $message, 'is_error' => true]]], $messages[2]);
    }
}
