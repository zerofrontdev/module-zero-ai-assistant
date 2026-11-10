<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Controller\Adminhtml\Job;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Block\Template;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Controller\Adminhtml\Chat\Index as ChatIndex;
use Zero\AiAssistant\Controller\Adminhtml\Job\Cancel;
use Zero\AiAssistant\Controller\Adminhtml\Job\Index;
use Zero\AiAssistant\Controller\Adminhtml\Job\Status;
use Zero\AiAssistant\Controller\Adminhtml\Job\Undo;
use Zero\AiAssistant\Model\Bulk\Bulk;
use Zero\AiAssistant\Model\Bulk\Jobs;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\AiAssistant\Model\Tools;
use Zero\AiAssistant\ViewModel\Chat;

/**
 * A bulk job's page and buttons work with Chat in the Admin off (a job confirmed through the Claude connector is
 * followed, stopped and undone there); the chat itself stays off. The job page only shows: GET, own job, bulk role.
 */
class AccessTest extends TestCase
{
    private function context(bool $allowed, ?RequestInterface $request = null, ?ResultFactory $results = null): Context
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(static fn (string $r) => $allowed && $r === 'Zero_AiAssistant::chat');
        $context = $this->createStub(Context::class);
        $context->method('getAuthorization')->willReturn($authorization);
        $context->method('getRequest')->willReturn($request ?? $this->createStub(RequestInterface::class));
        if ($results !== null) {
            $context->method('getResultFactory')->willReturn($results);
        }
        return $context;
    }

    private function chat(bool $on, int $userId = 5): Chat
    {
        $chat = $this->createStub(Chat::class);
        $chat->method('isAdminChatOn')->willReturn($on);
        $chat->method('userId')->willReturn($userId);
        return $chat;
    }

    private function tools(bool $bulk): Tools
    {
        $tools = $this->createStub(Tools::class);
        $tools->method('get')->willReturnCallback(fn (string $name) => $bulk && $name === 'bulk_write_products' ? $this->createStub(ToolInterface::class) : null);
        return $tools;
    }

    private static function isAllowed(object $controller): bool
    {
        $method = new \ReflectionMethod($controller, '_isAllowed');
        return (bool) $method->invoke($controller);
    }

    public function testJobActionsWorkWithChatOff(): void
    {
        foreach ([Status::class, Cancel::class, Undo::class, Index::class] as $class) {
            $controller = new $class($this->context(true), $this->chat(false), $this->createStub(Jobs::class), $this->createStub(Bulk::class), $this->tools(true));
            $this->assertTrue(self::isAllowed($controller), $class);
        }
    }

    public function testJobActionsNeedTheAssistantResource(): void
    {
        foreach ([Status::class, Cancel::class, Undo::class, Index::class] as $class) {
            $controller = new $class($this->context(false), $this->chat(true), $this->createStub(Jobs::class), $this->createStub(Bulk::class), $this->tools(true));
            $this->assertFalse(self::isAllowed($controller), $class);
        }
    }

    public function testTheChatStaysOffWhenSwitchedOff(): void
    {
        $this->assertFalse(self::isAllowed(new ChatIndex($this->context(true), $this->chat(false))));
        $this->assertTrue(self::isAllowed(new ChatIndex($this->context(true), $this->chat(true))));
    }

    public function testTheJobPageIsGetOnlyAndOpensWithoutTheSecretKey(): void
    {
        $page = new Index($this->context(true), $this->chat(false), $this->createStub(Jobs::class), $this->createStub(Bulk::class), $this->tools(true));

        $this->assertInstanceOf(HttpGetActionInterface::class, $page);
        $this->assertNotInstanceOf(HttpPostActionInterface::class, $page);
        $this->assertTrue((bool) (new \ReflectionMethod($page, '_validateSecretKey'))->invoke($page));
        $this->assertNotInstanceOf(HttpGetActionInterface::class, new Cancel($this->context(true), $this->chat(false), $this->createStub(Jobs::class), $this->createStub(Bulk::class), $this->tools(true)));
    }

    /**
     * @return array{0: Index, 1: \stdClass} the page and what its block was given
     */
    private function jobPage(?array $job, int $userId, bool $bulk, ?Title $title = null): array
    {
        $data = new \stdClass();
        $block = $this->createStub(Template::class);
        $block->method('setData')->willReturnCallback(static function ($key, $value = null) use ($block, $data) {
            $data->$key = $value;
            return $block;
        });
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getBlock')->willReturnCallback(static fn (string $name) => $name === 'zero.ai.job' ? $block : false);
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($title ?? $this->createStub(Title::class));
        $result = $this->createStub(Page::class);
        $result->method('getConfig')->willReturn($config);
        $result->method('getLayout')->willReturn($layout);
        $results = $this->createStub(ResultFactory::class);
        $results->method('create')->willReturnCallback(static fn (string $type) => $type === ResultFactory::TYPE_PAGE ? $result : null);
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'job_id' ? '3' : null);
        $jobs = $this->createStub(Jobs::class);
        $jobs->method('get')->willReturnCallback(static fn (int $id) => $id === 3 ? $job : null);
        $jobs->method('counts')->willReturn(['done' => 2]);
        $jobs->method('recent')->willReturn([]);

        return [new Index($this->context(true, $request, $results), $this->chat(false, $userId), $jobs, $this->createStub(Bulk::class), $this->tools($bulk)), $data];
    }

    public function testTheJobPageShowsTheUsersOwnJob(): void
    {
        $job = ['job_id' => 3, 'user_id' => 5, 'status' => Jobs::RUNNING, 'action_id' => 7, 'conversation_id' => 10];
        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('prepend')->with($this->callback(static fn ($t) => (string) $t === 'Bulk job #3'));
        [$page, $data] = $this->jobPage($job, 5, true, $title);

        $page->execute();

        $this->assertSame(3, $data->job_id);
        $this->assertSame(['job' => $job, 'counts' => ['done' => 2], 'recent' => []], $data->job);
    }

    public function testAnotherUsersJobOrARoleWithoutBulkWritingShowsNoJob(): void
    {
        $job = ['job_id' => 3, 'user_id' => 9, 'status' => Jobs::DONE];
        [$page, $data] = $this->jobPage($job, 5, true);
        $page->execute();
        $this->assertSame(3, $data->job_id);
        $this->assertNull($data->job, 'another user\'s job');

        [$page, $data] = $this->jobPage(['user_id' => 5] + $job, 5, false);
        $page->execute();
        $this->assertNull($data->job, 'the role lost bulk_write_products');

        [$page, $data] = $this->jobPage(null, 5, true);
        $page->execute();
        $this->assertNull($data->job, 'no such job');
    }
}
