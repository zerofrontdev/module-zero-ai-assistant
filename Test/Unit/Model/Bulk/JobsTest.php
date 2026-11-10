<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Bulk;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Bulk\Jobs;

/**
 * itemIds() reads a job's items in one status; with $since (the Reaper's
 * requeue of a job stuck undoing) only those changed at or after that time.
 */
class JobsTest extends TestCase
{
    /** @var array<string, mixed> the where() conditions of the select */
    private array $wheres = [];
    private mixed $from = null;

    private function jobs(array $ids): Jobs
    {
        $this->wheres = [];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnCallback(function ($table, $columns) use ($select) {
            $this->from = [$table, $columns];
            return $select;
        });
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select) {
            $this->wheres[$condition] = $value;
            return $select;
        });
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('fetchCol')->willReturn($ids);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);

        return new Jobs($resource);
    }

    public function testItemIdsOfAStatus(): void
    {
        $this->assertSame([11, 12], $this->jobs(['11', '12'])->itemIds(3, 'done'));
        $this->assertSame(['zero_ai_job_item', 'item_id'], $this->from);
        $this->assertSame(['job_id = ?' => 3, 'status = ?' => 'done'], $this->wheres, 'no time filter without $since');
    }

    public function testItemIdsSinceATime(): void
    {
        $this->assertSame([14], $this->jobs(['14'])->itemIds(3, 'done', '2026-10-28 21:00:00'));
        $this->assertSame(
            ['job_id = ?' => 3, 'status = ?' => 'done', 'updated_at >= ?' => '2026-10-28 21:00:00'],
            $this->wheres,
            'at or after: a product done in the same second as the take is included'
        );
    }
}
