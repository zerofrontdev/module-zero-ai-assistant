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
use Zero\AiAssistant\Model\Conversations;

/**
 * list_changes reads the newest changes of the conversation first, at most the limit, decoded as actions() does.
 */
class ConversationsTest extends TestCase
{
    public function testRecentActionsAreNewestFirstWithinTheLimit(): void
    {
        $calls = [];
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(static function (...$args) use ($select, $method, &$calls) {
                $calls[] = [$method, $args[0], $args[1] ?? null];
                return $select;
            });
        }
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('fetchAll')->willReturn([
            ['action_id' => '9', 'tool' => 'ship_order', 'input' => '{"number":"1"}', 'preview' => '{"title":"Ship order #1"}', 'undo' => null],
            ['action_id' => '8', 'tool' => 'update_product', 'input' => '{}', 'preview' => '{}', 'undo' => '{"sku":"MH01"}'],
        ]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);

        $rows = (new Conversations($resource))->recentActions(10, 2);

        $this->assertSame([
            ['from', 'zero_ai_action', '*'],
            ['where', 'conversation_id = ?', 10],
            ['order', 'action_id DESC', null],
            ['limit', 2, null],
        ], $calls);
        $this->assertSame(['9', '8'], array_column($rows, 'action_id'));
        $this->assertSame(['number' => '1'], $rows[0]['input']);
        $this->assertSame(['title' => 'Ship order #1'], $rows[0]['preview']);
        $this->assertNull($rows[0]['undo']);
        $this->assertSame(['sku' => 'MH01'], $rows[1]['undo']);
    }

    public function testALimitBelowOneReadsOne(): void
    {
        $limits = [];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnCallback(static function ($count) use ($select, &$limits) {
            $limits[] = $count;
            return $select;
        });
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('fetchAll')->willReturn([]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->assertSame([], (new Conversations($resource))->recentActions(10, 0));
        $this->assertSame([1], $limits);
    }
}
