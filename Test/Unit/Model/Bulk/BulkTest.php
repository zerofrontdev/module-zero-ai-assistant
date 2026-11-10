<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Bulk;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Bulk\Bulk;
use Zero\AiAssistant\Model\Bulk\Jobs;
use Zero\AiAssistant\Model\Bulk\StillWritingException;

/**
 * Bulk::undo is taken once: only the undo that moves the job from done (or cancelled) to undoing queues its products;
 * a job still writing, or with a product being written, is refused.
 */
class BulkTest extends TestCase
{
    private function job(string $status): array
    {
        return ['job_id' => 3, 'action_id' => 7, 'user_id' => 5, 'status' => $status];
    }

    public function testUndoOfARunningJobThrowsStillWriting(): void
    {
        $jobs = $this->createMock(Jobs::class);
        $jobs->expects($this->never())->method('setStatus');
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $this->expectException(StillWritingException::class);
        $this->expectExceptionMessage('It is still writing: cancel it first, or wait.');
        (new Bulk($jobs, $publisher, $this->createStub(ScopeConfigInterface::class)))->undo($this->job(Jobs::RUNNING));
    }

    public function testUndoOfAJobNeitherDoneNorStoppedQueuesNothing(): void
    {
        foreach ([Jobs::UNDOING, Jobs::UNDONE, 'something'] as $status) {
            $jobs = $this->createMock(Jobs::class);
            $jobs->expects($this->never())->method('setStatus');
            $jobs->expects($this->never())->method('itemIds');
            $publisher = $this->createMock(PublisherInterface::class);
            $publisher->expects($this->never())->method('publish');

            $this->assertSame(0, (new Bulk($jobs, $publisher, $this->createStub(ScopeConfigInterface::class)))->undo($this->job($status)), $status);
        }
    }

    public function testUndoWaitsForAProductBeingWritten(): void
    {
        $jobs = $this->createMock(Jobs::class);
        $jobs->method('counts')->with(3)->willReturn(['done' => 4, 'working' => 1]);
        $jobs->expects($this->never())->method('setStatus');
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('One product is still being written: try again in a minute.');
        (new Bulk($jobs, $publisher, $this->createStub(ScopeConfigInterface::class)))->undo($this->job(Jobs::CANCELLED));
    }

    public function testUndoIsTakenOnce(): void
    {
        $jobs = $this->createMock(Jobs::class);
        $jobs->method('counts')->willReturn(['done' => 2]);
        $jobs->expects($this->once())->method('setStatus')->with(3, Jobs::UNDOING, Jobs::DONE)->willReturn(false);
        $jobs->expects($this->never())->method('itemIds');
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        try {
            (new Bulk($jobs, $publisher, $this->createStub(ScopeConfigInterface::class)))->undo($this->job(Jobs::DONE));
            $this->fail('a lost compare-and-set must throw');
        } catch (StillWritingException $e) {
            $this->fail('not a still-writing refusal');
        } catch (LocalizedException $e) {
            $this->assertSame('It is being undone already.', $e->getMessage());
        }
    }

    public function testUndoQueuesEveryWrittenProduct(): void
    {
        $statuses = [];
        $jobs = $this->createMock(Jobs::class);
        $jobs->method('counts')->willReturn(['done' => 2, 'skipped' => 3]);
        $jobs->expects($this->once())->method('setStatus')->willReturnCallback(static function (int $id, string $status, ?string $from) use (&$statuses) {
            $statuses[] = [$id, $status, $from];
            return true;
        });
        $jobs->expects($this->once())->method('itemIds')->with(3, 'done')->willReturn([11, 12]);
        $published = [];
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->exactly(2))->method('publish')->willReturnCallback(static function (string $topic, $body) use (&$published) {
            $published[] = [$topic, json_decode((string) $body, true)];
            return null;
        });

        $queued = (new Bulk($jobs, $publisher, $this->createStub(ScopeConfigInterface::class)))->undo($this->job(Jobs::CANCELLED));

        $this->assertSame(2, $queued);
        $this->assertSame([[3, Jobs::UNDOING, Jobs::CANCELLED]], $statuses, 'taken from the status it was read in; undone later by the consumer');
        $this->assertSame([
            ['zero.ai.bulk', ['item_id' => 11, 'op' => 'undo']],
            ['zero.ai.bulk', ['item_id' => 12, 'op' => 'undo']],
        ], $published);
    }

    public function testUndoWithNothingWrittenEndsUndone(): void
    {
        $statuses = [];
        $jobs = $this->createMock(Jobs::class);
        $jobs->method('counts')->willReturn(['skipped' => 5]);
        $jobs->expects($this->exactly(2))->method('setStatus')->willReturnCallback(static function (int $id, string $status, ?string $from) use (&$statuses) {
            $statuses[] = [$id, $status, $from];
            return true;
        });
        $jobs->method('itemIds')->willReturn([]);
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $queued = (new Bulk($jobs, $publisher, $this->createStub(ScopeConfigInterface::class)))->undo($this->job(Jobs::CANCELLED));

        $this->assertSame(0, $queued);
        $this->assertSame([[3, Jobs::UNDOING, Jobs::CANCELLED], [3, Jobs::UNDONE, Jobs::UNDOING]], $statuses);
    }

    public function testRequeueUndoQueuesOnlyTheDoneProductsChangedSinceTheLastTake(): void
    {
        $jobs = $this->createMock(Jobs::class);
        $jobs->expects($this->once())->method('itemIds')->with(3, 'done', '2026-10-28 21:00:00')->willReturn([14, 15]);
        $jobs->expects($this->never())->method('setStatus');
        $published = [];
        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(static function (string $topic, string $data) use (&$published) {
            $published[] = [$topic, json_decode($data, true)];
            return null;
        });

        $queued = (new Bulk($jobs, $publisher, $this->createStub(ScopeConfigInterface::class)))
            ->requeueUndo($this->job(Jobs::UNDOING), '2026-10-28 21:00:00');

        $this->assertSame(2, $queued);
        $this->assertSame([
            ['zero.ai.bulk', ['item_id' => 14, 'op' => 'undo']],
            ['zero.ai.bulk', ['item_id' => 15, 'op' => 'undo']],
        ], $published);
    }

    public function testRequeueUndoOfAJobNotUndoingQueuesNothing(): void
    {
        foreach ([Jobs::DONE, Jobs::UNDONE, Jobs::RUNNING, Jobs::CANCELLED] as $status) {
            $jobs = $this->createMock(Jobs::class);
            $jobs->expects($this->never())->method('itemIds');
            $publisher = $this->createMock(PublisherInterface::class);
            $publisher->expects($this->never())->method('publish');

            $this->assertSame(
                0,
                (new Bulk($jobs, $publisher, $this->createStub(ScopeConfigInterface::class)))->requeueUndo($this->job($status), '2026-10-28 21:00:00'),
                $status
            );
        }
    }

    public function testRequeueUndoWithNothingLateQueuesNothing(): void
    {
        $jobs = $this->createStub(Jobs::class);
        $jobs->method('itemIds')->willReturn([]);
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $this->assertSame(0, (new Bulk($jobs, $publisher, $this->createStub(ScopeConfigInterface::class)))->requeueUndo($this->job(Jobs::UNDOING), '2026-10-28 21:00:00'));
    }
}
