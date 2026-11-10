<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\AiAssistant\Model\Client;
use Zero\AiAssistant\Model\Compactor;
use Zero\AiAssistant\Model\Conversations;

/**
 * A long conversation: the last exchanges in full, a summary before them,
 * the cut never between a tool call and its result, the summary extended
 * rather than rewritten from the start.
 */
#[AllowMockObjectsWithoutExpectations]
class CompactorTest extends TestCase
{
    private Client&MockObject $client;
    private Conversations&MockObject $conversations;
    private ?array $saved = null;
    /** @var string[] what the summaries were asked from */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->client->method('send')->willReturnCallback(function ($system, $messages) {
            $this->asked[] = $messages[0]['content'][0]['text'];
            return ['content' => [['type' => 'text', 'text' => '- summary ' . count($this->asked)]]];
        });
        $this->conversations = $this->createMock(Conversations::class);
        $this->conversations->method('summary')->willReturnCallback(fn () => $this->saved);
        $this->conversations->method('saveSummary')->willReturnCallback(function ($id, $summary, $upto) {
            $this->saved = ['summary' => $summary, 'upto' => $upto];
        });
    }

    public function testExchangeStartsSkipToolResultsAndNotes(): void
    {
        $messages = [
            $this->user('Show orders'),
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'list_orders', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '[]']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'None today.']]],
            $this->user('[The admin confirmed change #3 (update_product): done.]'),
            ['role' => 'user', 'content' => [['type' => 'zero_upload', 'upload_id' => 4]]],
        ];
        $this->assertSame([0, 5], Compactor::starts($messages));
    }

    public function testShortConversationsGoInFull(): void
    {
        $messages = $this->conversation(15);
        $prepared = $this->compactor(10)->prepare(1, $messages);
        $this->assertNull($prepared['summary']);
        $this->assertCount(30, $prepared['messages']);
        $this->assertSame([], $this->asked);
    }

    public function testALongOneKeepsTheLastExchangesAndSummarizesTheRest(): void
    {
        $messages = $this->conversation(16);
        $prepared = $this->compactor(10)->prepare(1, $messages);
        $this->assertSame('- summary 1', $prepared['summary']);
        $this->assertCount(20, $prepared['messages']);
        $this->assertSame('Question 7', $prepared['messages'][0]['content'][0]['text']);
        $this->assertSame(['summary' => '- summary 1', 'upto' => 12], $this->saved);
        $this->assertStringContainsString('Admin: Question 1', $this->asked[0]);
        $this->assertStringNotContainsString('Question 7', $this->asked[0]);

        // After it, the summary stays until the rest is half as long again (15 exchanges), then grows.
        $prepared = $this->compactor(10)->prepare(1, $this->conversation(21));
        $this->assertSame('- summary 1', $prepared['summary']);
        $this->assertCount(30, $prepared['messages']);
        $prepared = $this->compactor(10)->prepare(1, $this->conversation(22));
        $this->assertSame('- summary 2', $prepared['summary']);
        $this->assertCount(20, $prepared['messages']);
        $this->assertStringContainsString("Summary so far:\n- summary 1", $this->asked[1]);
        $this->assertStringContainsString('Admin: Question 7', $this->asked[1]);
        $this->assertStringNotContainsString('Admin: Question 1' . "\n", $this->asked[1], 'the old part is not sent again');
        $this->assertSame('Question 13', $prepared['messages'][0]['content'][0]['text']);
    }

    private function compactor(int $keep): Compactor
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($keep);
        return new Compactor($this->client, $this->conversations, $config, $this->createStub(LoggerInterface::class));
    }

    /**
     * $n exchanges: a question and an answer each, message ids from 1.
     */
    private function conversation(int $n): array
    {
        $messages = [];
        for ($i = 1; $i <= $n; $i++) {
            $messages[] = ['message_id' => 2 * $i - 1] + $this->user('Question ' . $i);
            $messages[] = ['message_id' => 2 * $i, 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Answer ' . $i]]];
        }
        return $messages;
    }

    private function user(string $text): array
    {
        return ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]]];
    }
}
