<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Bulk;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\AiAssistant\Model\Acl\UserAuthorization;
use Zero\AiAssistant\Model\Acl\UserAuthorizationFactory;
use Zero\AiAssistant\Model\Bulk\Consumer;
use Zero\AiAssistant\Model\Bulk\Jobs;
use Zero\AiAssistant\Model\Bulk\Writer;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Tool\Catalog\ProductWriter;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\AiAssistant\Model\Tools;
use Zero\AiAssistant\Model\ToolsFactory;

/**
 * A bulk job runs in the queue after the admin confirmed it: each product is
 * written only while the admin user may still do it.
 */
class ConsumerTest extends TestCase
{
    /**
     * A consumer whose job's admin user may still write products in bulk.
     */
    private function consumer(Jobs $jobs, ProductWriter $writer, Conversations $conversations): Consumer
    {
        $authorization = $this->createStub(UserAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $authorizations = $this->createStub(UserAuthorizationFactory::class);
        $authorizations->method('create')->willReturn($authorization);
        $tools = $this->createStub(Tools::class);
        $tools->method('get')->willReturnCallback(fn (string $name) => $name === 'bulk_write_products' ? $this->createStub(ToolInterface::class) : null);
        $toolsFactory = $this->createStub(ToolsFactory::class);
        $toolsFactory->method('create')->willReturn($tools);

        return new Consumer(
            $jobs,
            $this->createStub(Writer::class),
            $writer,
            $this->createStub(ProductRepositoryInterface::class),
            $conversations,
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(LoggerInterface::class),
            $authorizations,
            $toolsFactory
        );
    }

    public function testUndoPutsTheOldTextsBackVerbatim(): void
    {
        $before = ['description' => '<p>Intro</p><iframe src="https://www.youtube.com/embed/x"></iframe>{{widget type="X"}}', 'meta_title' => 'Old'];
        $job = ['job_id' => 3, 'user_id' => 5, 'status' => Jobs::UNDOING, 'conversation_id' => 1, 'action_id' => 9, 'fields' => ['description', 'meta_title'], 'brief' => ''];
        $jobs = $this->createMock(Jobs::class);
        $jobs->method('item')->with(7)->willReturn(['item_id' => 7, 'job_id' => 3, 'sku' => 'ED-1', 'status' => 'done', 'before' => $before]);
        $jobs->method('get')->with(3)->willReturn($job);
        $jobs->method('counts')->willReturn(['undone' => 1]);
        $jobs->expects($this->once())->method('updateItem')->with(7, ['status' => 'undone'], 'done')->willReturn(true);
        $jobs->expects($this->once())->method('setStatus')->with(3, Jobs::UNDONE, Jobs::UNDOING)->willReturn(true);
        $writer = $this->createMock(ProductWriter::class);
        $writer->expects($this->once())->method('restore')
            ->with(['sku' => 'ED-1'] + $before, $this->callback(static fn (Context $c) => $c->userId === 5 && $c->conversationId === 1));
        $writer->expects($this->never())->method('update');
        $conversations = $this->createMock(Conversations::class);
        $conversations->expects($this->once())->method('addMessage')
            ->with(1, 'user', [['type' => 'text', 'text' => '[The admin undid bulk job #3 (change #9): 1 products have their old texts back.]']]);

        $this->consumer($jobs, $writer, $conversations)->process('{"item_id": 7, "op": "undo"}');
    }

    public function testFinishSettlesARunningJobWithNothingLeft(): void
    {
        $jobs = $this->createMock(Jobs::class);
        $jobs->method('counts')->with(3)->willReturn(['done' => 4, 'failed' => 2]);
        $jobs->expects($this->once())->method('setStatus')->with(3, Jobs::DONE, Jobs::RUNNING)->willReturn(true);
        $conversations = $this->createMock(Conversations::class);
        $conversations->expects($this->once())->method('addMessage')
            ->with(1, 'user', [['type' => 'text', 'text' => '[The admin\'s bulk job #3 (change #9) finished: 4 written, 2 failed.]']]);

        $this->consumer($jobs, $this->createStub(ProductWriter::class), $conversations)
            ->finish(['job_id' => 3, 'status' => Jobs::RUNNING, 'conversation_id' => 1, 'action_id' => 9, 'user_id' => 5]);
    }

    public function testFinishLeavesAJobWithAProductBeingWritten(): void
    {
        $jobs = $this->createMock(Jobs::class);
        $jobs->method('counts')->willReturn(['done' => 4, 'working' => 1]);
        $jobs->expects($this->never())->method('setStatus');
        $conversations = $this->createMock(Conversations::class);
        $conversations->expects($this->never())->method('addMessage');

        $this->consumer($jobs, $this->createStub(ProductWriter::class), $conversations)
            ->finish(['job_id' => 3, 'status' => Jobs::RUNNING, 'conversation_id' => 1, 'action_id' => 9, 'user_id' => 5]);
    }

    public function testAUserWhoLostTheRightWritesNothing(): void
    {
        $jobs = $this->createMock(Jobs::class);
        $jobs->method('item')->willReturn(['item_id' => 7, 'job_id' => 3, 'sku' => 'ED-1', 'status' => 'pending']);
        $jobs->method('get')->willReturn(['job_id' => 3, 'user_id' => 5, 'status' => Jobs::RUNNING, 'conversation_id' => 1, 'action_id' => 9, 'fields' => ['description'], 'brief' => '']);
        $jobs->method('counts')->willReturn(['failed' => 1]);
        $jobs->expects($this->once())->method('updateItem')
            ->with(7, $this->callback(static fn (array $f) => $f['status'] === 'failed' && str_contains($f['error'], 'may not change products')));
        $authorization = $this->createStub(UserAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $authorizations = $this->createStub(UserAuthorizationFactory::class);
        $authorizations->method('create')->willReturn($authorization);
        $tools = $this->createStub(Tools::class);
        $tools->method('get')->willReturn(null);
        $toolsFactory = $this->createStub(ToolsFactory::class);
        $toolsFactory->method('create')->willReturn($tools);
        $writer = $this->createMock(ProductWriter::class);
        $writer->expects($this->never())->method('update');

        (new Consumer(
            $jobs,
            $this->createStub(Writer::class),
            $writer,
            $this->createStub(ProductRepositoryInterface::class),
            $this->createStub(Conversations::class),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(LoggerInterface::class),
            $authorizations,
            $toolsFactory
        ))->process('{"item_id": 7, "op": "write"}');
    }
}
