<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Bulk\Bulk;
use Zero\AiAssistant\Model\Bulk\Consumer;
use Zero\AiAssistant\Model\Bulk\Jobs;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Reaper;

/**
 * Changes and bulk products stuck for over an hour are marked failed, each row by a compare-and-set on its status:
 * only a row actually changed gets a note, and a job whose stuck products failed is settled. A job stuck undoing
 * (a product done after Undo All queued the others) gets its late products queued again, or is settled.
 */
class ReaperTest extends TestCase
{
    private const NOW = 1793232000; // 2026-10-29 00:00:00 UTC
    private const MESSAGE = 'It stopped without finishing (over an hour): check what it changed before trying again.';

    /** @var array<string, array{wheres: array, limit: int|null, columns: mixed}> the selects, by table */
    private array $selects = [];

    /** @var array<int, \stdClass> each select's from/where/limit, by object id */
    private array $lastStates = [];

    /** @var array<int, array{0: string, 1: array, 2: array}> the updates */
    private array $updates = [];

    /**
     * @param array<int, array> $actions the running changes the select finds
     * @param array<int, array> $items the working products it finds
     * @param int[] $updated what each update returns, in order
     * @param array<int, array> $undoing the undoing jobs it finds
     */
    private function db(array $actions, array $items, array $updated = [], array $undoing = []): ResourceConnection
    {
        $this->selects = [];
        $this->updates = [];
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturnCallback(function () {
            $select = $this->createStub(Select::class);
            $state = (object) ['table' => '', 'wheres' => [], 'limit' => null, 'columns' => null];
            $select->method('from')->willReturnCallback(static function ($table, $columns = '*') use ($select, $state) {
                $state->table = $table;
                $state->columns = $columns;
                return $select;
            });
            $select->method('where')->willReturnCallback(static function ($cond, $value = null) use ($select, $state) {
                $state->wheres[$cond] = $value;
                return $select;
            });
            $select->method('limit')->willReturnCallback(static function ($count) use ($select, $state) {
                $state->limit = $count;
                return $select;
            });
            $this->lastStates[spl_object_id($select)] = $state;
            return $select;
        });
        $db->method('fetchAll')->willReturnCallback(function ($select) use ($actions, $items, $undoing) {
            $state = $this->lastStates[spl_object_id($select)];
            $this->selects[$state->table] = ['wheres' => $state->wheres, 'limit' => $state->limit, 'columns' => $state->columns];
            return match ($state->table) {
                'zero_ai_action' => $actions,
                'zero_ai_job' => $undoing,
                default => $items,
            };
        });
        $db->method('update')->willReturnCallback(function (string $table, array $bind, array $where) use (&$updated) {
            $this->updates[] = [$table, $bind, $where];
            return $updated ? array_shift($updated) : 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    public function testRunningChangesOverAnHourOldFailWithANote(): void
    {
        $resource = $this->db([['action_id' => 7, 'conversation_id' => 10, 'tool' => 'ship_order']], []);
        $conversations = $this->createMock(Conversations::class);
        $conversations->expects($this->once())->method('addMessage')
            ->with(10, 'user', [['type' => 'text', 'text' => '[The admin\'s change #7 (ship_order) stopped without finishing and is marked failed.]']]);

        $result = (new Reaper($resource, $conversations, $this->createStub(Jobs::class), $this->createStub(Consumer::class), $this->createStub(Bulk::class)))->reap(self::NOW);

        $this->assertSame(['changes' => 1, 'items' => 0, 'undos' => 0], $result);
        $this->assertSame(['status = ?' => 'running', 'updated_at < ?' => '2026-10-28 23:00:00'], $this->selects['zero_ai_action']['wheres']);
        $this->assertSame(500, $this->selects['zero_ai_action']['limit']);
        $this->assertSame([[
            'zero_ai_action',
            ['status' => 'failed', 'result' => self::MESSAGE],
            ['action_id = ?' => 7, 'status = ?' => 'running'],
        ]], $this->updates);
    }

    public function testARaceLostRowGetsNoNote(): void
    {
        $resource = $this->db([
            ['action_id' => 7, 'conversation_id' => 10, 'tool' => 'ship_order'],
            ['action_id' => 8, 'conversation_id' => 11, 'tool' => 'update_product'],
        ], [], [0, 1]);
        $notes = [];
        $conversations = $this->createStub(Conversations::class);
        $conversations->method('addMessage')->willReturnCallback(static function (int $id, string $role, array $content) use (&$notes) {
            $notes[] = [$id, $content[0]['text']];
            return 1;
        });

        $result = (new Reaper($resource, $conversations, $this->createStub(Jobs::class), $this->createStub(Consumer::class), $this->createStub(Bulk::class)))->reap(self::NOW);

        $this->assertSame(1, $result['changes'], 'change #7 finished meanwhile');
        $this->assertSame([[11, '[The admin\'s change #8 (update_product) stopped without finishing and is marked failed.]']], $notes);
    }

    public function testStuckItemsFailAndTheirJobIsSettled(): void
    {
        $resource = $this->db([], [
            ['item_id' => 21, 'job_id' => 3],
            ['item_id' => 22, 'job_id' => 3],
            ['item_id' => 23, 'job_id' => 4],
        ]);
        $failed = [];
        $jobs = $this->createStub(Jobs::class);
        $jobs->method('updateItem')->willReturnCallback(static function (int $id, array $fields, ?string $from) use (&$failed) {
            $failed[] = [$id, $fields, $from];
            return $id !== 23; // item 23 finished meanwhile
        });
        $job = ['job_id' => 3, 'status' => Jobs::RUNNING, 'conversation_id' => 10, 'action_id' => 7, 'user_id' => 5];
        $jobs->method('get')->willReturnCallback(static fn (int $id) => $id === 3 ? $job : ['job_id' => $id]);
        $consumer = $this->createMock(Consumer::class);
        $consumer->expects($this->once())->method('finish')->with($job);
        $conversations = $this->createMock(Conversations::class);
        $conversations->expects($this->never())->method('addMessage');

        $result = (new Reaper($resource, $conversations, $jobs, $consumer, $this->createStub(Bulk::class)))->reap(self::NOW);

        $this->assertSame(['changes' => 0, 'items' => 2, 'undos' => 0], $result);
        $this->assertSame(['status = ?' => 'working', 'updated_at < ?' => '2026-10-28 23:00:00'], $this->selects['zero_ai_job_item']['wheres']);
        $this->assertSame(1000, $this->selects['zero_ai_job_item']['limit']);
        $this->assertSame([
            [21, ['status' => 'failed', 'error' => self::MESSAGE], 'working'],
            [22, ['status' => 'failed', 'error' => self::MESSAGE], 'working'],
            [23, ['status' => 'failed', 'error' => self::MESSAGE], 'working'],
        ], $failed);
    }

    public function testNothingStuckChangesNothing(): void
    {
        $resource = $this->db([], []);
        $consumer = $this->createMock(Consumer::class);
        $consumer->expects($this->never())->method('finish');

        $result = (new Reaper($resource, $this->createStub(Conversations::class), $this->createStub(Jobs::class), $consumer, $this->createStub(Bulk::class)))->reap(self::NOW);

        $this->assertSame(['changes' => 0, 'items' => 0, 'undos' => 0], $result);
        $this->assertSame([], $this->updates);
    }

    public function testAJobStuckUndoingIsTakenByCompareAndSetAndItsLateProductsQueued(): void
    {
        $resource = $this->db([], [], [1], [['job_id' => 3, 'updated_at' => '2026-10-28 21:00:00']]);
        $job = ['job_id' => 3, 'status' => Jobs::UNDOING, 'conversation_id' => 10, 'action_id' => 7, 'user_id' => 5];
        $jobs = $this->createStub(Jobs::class);
        $jobs->method('get')->willReturnCallback(static fn (int $id) => $id === 3 ? $job : null);
        $bulk = $this->createMock(Bulk::class);
        $bulk->expects($this->once())->method('requeueUndo')->with($job, '2026-10-28 21:00:00')->willReturn(2);
        $consumer = $this->createMock(Consumer::class);
        $consumer->expects($this->never())->method('finish');

        $result = (new Reaper($resource, $this->createStub(Conversations::class), $jobs, $consumer, $bulk))->reap(self::NOW);

        $this->assertSame(['changes' => 0, 'items' => 0, 'undos' => 1], $result);
        $this->assertSame(
            ['status = ?' => Jobs::UNDOING, 'updated_at < ?' => '2026-10-28 23:00:00'],
            $this->selects['zero_ai_job']['wheres'],
            'only jobs undoing for over an hour'
        );
        $this->assertSame(100, $this->selects['zero_ai_job']['limit']);
        $this->assertSame(['job_id', 'updated_at'], $this->selects['zero_ai_job']['columns']);
        $this->assertSame([[
            'zero_ai_job',
            ['updated_at' => '2026-10-29 00:00:00'],
            ['job_id = ?' => 3, 'status = ?' => Jobs::UNDOING, 'updated_at = ?' => '2026-10-28 21:00:00'],
        ]], $this->updates, 'taken by moving updated_at to now, only from the value it was read with');
    }

    public function testAJobStuckUndoingWithNothingLeftToQueueIsSettled(): void
    {
        $resource = $this->db([], [], [1], [['job_id' => 3, 'updated_at' => '2026-10-28 21:00:00']]);
        $job = ['job_id' => 3, 'status' => Jobs::UNDOING, 'conversation_id' => 10, 'action_id' => 7, 'user_id' => 5];
        $jobs = $this->createStub(Jobs::class);
        $jobs->method('get')->willReturn($job);
        $bulk = $this->createMock(Bulk::class);
        $bulk->expects($this->once())->method('requeueUndo')->willReturn(0);
        $consumer = $this->createMock(Consumer::class);
        $consumer->expects($this->once())->method('finish')->with($job);

        $result = (new Reaper($resource, $this->createStub(Conversations::class), $jobs, $consumer, $bulk))->reap(self::NOW);

        $this->assertSame(1, $result['undos']);
    }

    public function testAnUndoingJobTakenOrSettledMeanwhileIsLeftAlone(): void
    {
        $resource = $this->db([], [], [0, 1], [
            ['job_id' => 3, 'updated_at' => '2026-10-28 21:00:00'],
            ['job_id' => 4, 'updated_at' => '2026-10-28 22:00:00'],
        ]);
        $job4 = ['job_id' => 4, 'status' => Jobs::UNDOING, 'conversation_id' => 11, 'action_id' => 8, 'user_id' => 5];
        $jobs = $this->createMock(Jobs::class);
        $jobs->expects($this->once())->method('get')->with(4)->willReturn($job4);
        $bulk = $this->createMock(Bulk::class);
        $bulk->expects($this->once())->method('requeueUndo')->with($job4, '2026-10-28 22:00:00')->willReturn(1);
        $consumer = $this->createMock(Consumer::class);
        $consumer->expects($this->never())->method('finish');

        $result = (new Reaper($resource, $this->createStub(Conversations::class), $jobs, $consumer, $bulk))->reap(self::NOW);

        $this->assertSame(1, $result['undos'], 'job 3 lost the compare-and-set (another run, or settled)');
        $this->assertCount(2, $this->updates);
    }

    public function testAnUndoingJobGoneAfterTheClaimIsNotCounted(): void
    {
        $resource = $this->db([], [], [1], [['job_id' => 3, 'updated_at' => '2026-10-28 21:00:00']]);
        $jobs = $this->createStub(Jobs::class);
        $jobs->method('get')->willReturn(null);
        $bulk = $this->createMock(Bulk::class);
        $bulk->expects($this->never())->method('requeueUndo');
        $consumer = $this->createMock(Consumer::class);
        $consumer->expects($this->never())->method('finish');

        $result = (new Reaper($resource, $this->createStub(Conversations::class), $jobs, $consumer, $bulk))->reap(self::NOW);

        $this->assertSame(0, $result['undos']);
    }
}
