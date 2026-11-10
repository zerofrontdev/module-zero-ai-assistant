<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Retention;

class RetentionTest extends TestCase
{
    private const NOW = 1793232000; // 2026-10-29 00:00:00 UTC

    /** @var array<int, array{0: string, 1: array}> the deletes, in order */
    private array $deletes = [];

    /** @var string[] the tables of the union the client purge excludes */
    private array $unionTables = [];

    /** @var array<int, mixed> their columns */
    private array $unionColumns = [];

    /** the select union() made: the one the client purge must exclude */
    private ?Select $unionSelect = null;

    public function testWithNothingKeptForGoodOnlyTheConnectorsExpiredCodesGo(): void
    {
        $removed = $this->retention(0, true)->purge(self::NOW);

        $this->assertSame(['mcp_codes', 'mcp_pending', 'mcp_drafts', 'mcp_clients'], array_keys($removed));
        $this->assertSame(['zero_mcp_code', 'zero_mcp_pending', 'zero_mcp_draft', 'zero_mcp_client'], array_column($this->deletes, 0));
        $this->assertSame(['expires_at < ?' => '2026-10-29 00:00:00'], $this->deletes[0][1]);
        $this->assertUnusedClientsWhere($this->deletes[3][1]);
    }

    public function testUnusedClientsGoEvenWhenEverythingIsKept(): void
    {
        $removed = $this->retention(0, true, 4)->purge(self::NOW);

        $this->assertSame(4, $removed['mcp_clients']);
        $this->assertArrayNotHasKey('messages', $removed, 'Keep Data For 0 keeps the rest');
        $this->assertSame('zero_mcp_client', $this->deletes[3][0]);
        $this->assertUnusedClientsWhere($this->deletes[3][1]);
    }

    public function testWithNinetyDaysTheUnusedClientCutStaysSevenDays(): void
    {
        $this->retention(90, true)->purge(self::NOW);

        $where = array_column($this->deletes, 1, 0);
        $this->assertUnusedClientsWhere($where['zero_mcp_client']);
    }

    /**
     * Seven days before NOW, and only clients with no token, no pending consent and no code (a sign-in under way).
     */
    private function assertUnusedClientsWhere(array $where): void
    {
        $this->assertSame(['created_at < ?', 'client_id NOT IN (?)'], array_keys($where));
        $this->assertSame('2026-10-22 00:00:00', $where['created_at < ?']);
        $this->assertSame(['zero_mcp_token', 'zero_mcp_pending', 'zero_mcp_code'], $this->unionTables);
        $this->assertSame(['client_id', 'client_id', 'client_id'], $this->unionColumns);
        $this->assertNotNull($this->unionSelect);
        $this->assertSame($this->unionSelect, $where['client_id NOT IN (?)'], 'the union itself is what the clients are checked against');
    }

    public function testNinetyDaysCutsAtThatDateAndLeavesRunningChangesAlone(): void
    {
        $removed = $this->retention(90, true)->purge(self::NOW);

        $where = array_column($this->deletes, 1, 0);
        $this->assertSame(['created_at < ?' => '2026-07-31 00:00:00'], $where['zero_ai_message']);
        $this->assertSame(['done', 'cancelled', 'failed', 'undone', 'expired'], $where['zero_ai_action']['status IN (?)']);
        $this->assertSame('2026-07-31 00:00:00', $where['zero_mcp_token']['(revoked_at IS NOT NULL AND revoked_at < ?) OR refresh_expires_at < ?']);
        $this->assertArrayHasKey('conversations', $removed);
        $this->assertArrayHasKey('connections', $removed);
    }

    public function testWithoutTheConnectorItsTablesAreLeftOut(): void
    {
        $removed = $this->retention(90, false)->purge(self::NOW);

        $this->assertArrayNotHasKey('mcp_codes', $removed);
        $this->assertArrayNotHasKey('connections', $removed);
        $this->assertSame([], array_filter(array_column($this->deletes, 0), static fn (string $t): bool => str_starts_with($t, 'zero_mcp_')));
    }

    private function retention(int $days, bool $mcp, int $clientsRemoved = 0): Retention
    {
        $this->deletes = [];
        $this->unionTables = [];
        $this->unionColumns = [];
        $this->unionSelect = $this->createStub(Select::class);
        $froms = [];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnCallback(function ($table, $columns = '*') use ($select, &$froms) {
            $froms[] = [$table, $columns];
            return $select;
        });
        $select->method('union')->willReturnCallback(function (array $selects) use ($select, &$froms) {
            // Each part of the union was made by its own from(): the last count($selects) ones.
            foreach (array_slice($froms, -count($selects)) as [$table, $columns]) {
                $this->unionTables[] = $table;
                $this->unionColumns[] = $columns;
            }
            return $this->unionSelect;
        });
        $select->method('where')->willReturnSelf();
        $db = $this->createStub(AdapterInterface::class);
        $db->method('isTableExists')->willReturn($mcp);
        $db->method('select')->willReturn($select);
        $db->method('fetchCol')->willReturn([]);
        $db->method('fetchPairs')->willReturn([]);
        $db->method('delete')->willReturnCallback(function (string $table, array $where) use ($clientsRemoved): int {
            $this->deletes[] = [$table, $where];
            return $table === 'zero_mcp_client' ? $clientsRemoved : 0;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(static fn (string $path) => $path === Retention::DAYS_PATH ? (string) $days : null);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->createStub(WriteInterface::class));

        return new Retention($resource, $config, $filesystem);
    }
}
