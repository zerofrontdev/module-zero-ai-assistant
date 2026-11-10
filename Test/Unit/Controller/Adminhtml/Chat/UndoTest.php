<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Block\Template;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ViewInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Controller\Adminhtml\Chat\Undo;
use Zero\AiAssistant\Model\Agent;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\ViewModel\Chat;

/**
 * The chat's Undo button shows the card again; what went wrong (failed, can't be undone, busy) is said on it.
 */
class UndoTest extends TestCase
{
    /**
     * @return array<string, mixed> what the card template was given
     */
    private function undo(array $agentResult): array
    {
        $given = new \stdClass();
        $given->data = [];
        $block = $this->createStub(Template::class);
        $block->method('setTemplate')->willReturnSelf();
        $block->method('addData')->willReturnCallback(static function (array $data) use ($block, $given) {
            $given->data = $data;
            return $block;
        });
        $block->method('toHtml')->willReturn('<div>card</div>');
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('createBlock')->willReturn($block);
        $view = $this->createStub(ViewInterface::class);
        $view->method('getLayout')->willReturn($layout);
        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();
        $results = $this->createStub(ResultFactory::class);
        $results->method('create')->willReturn($raw);
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'action_id' ? '7' : null);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getView')->willReturn($view);
        $context->method('getResultFactory')->willReturn($results);
        $chat = $this->createStub(Chat::class);
        $chat->method('userId')->willReturn(5);
        $agent = $this->createMock(Agent::class);
        $agent->expects($this->once())->method('undo')->with(7, 5)->willReturn($agentResult);
        $conversations = $this->createStub(Conversations::class);
        $conversations->method('action')->willReturn(['action_id' => 7, 'status' => 'done']);

        (new Undo($context, $chat, $agent, $conversations))->execute();

        return $given->data;
    }

    public function testWhatCannotBeDoneIsSaidOnTheCard(): void
    {
        foreach ([
            'failed' => 'No product with SKU MH01.',
            'not_undoable' => 'Change #7 (set_settings) can\'t be undone: nothing was kept to put back. Change it again instead.',
            'busy' => 'Change #7 is being undone already.',
        ] as $status => $message) {
            $given = $this->undo(['status' => $status, 'result' => $message]);
            $this->assertSame($message, $given['action_error'], $status);
            $this->assertSame(['action_id' => 7, 'status' => 'done'], $given['action']);
        }
    }

    public function testAnUndoneOrQueuedChangeShowsNoError(): void
    {
        foreach ([
            ['status' => 'undone', 'result' => ['sku' => 'MH01']],
            ['status' => 'undoing', 'result' => ['job_id' => 3, 'products' => 12]],
            ['status' => 'already_undone', 'result' => 'Change #7 is undone already.'],
        ] as $result) {
            $this->assertNull($this->undo($result)['action_error'], $result['status']);
        }
    }
}
