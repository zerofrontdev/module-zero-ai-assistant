<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\AiAssistant\Model\Client;
use Zero\Cms\Model\Ai\MessagesApi;

class ClientTest extends TestCase
{
    public function testToolsInstructionsAndTheConversationAreMarkedForTheCache(): void
    {
        $sent = null;
        $to = null;
        $curl = $this->createStub(Curl::class);
        $curl->method('post')->willReturnCallback(function ($url, $body) use (&$sent, &$to) {
            $sent = json_decode($body, true);
            $to = $url;
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{"content":[],"stop_reason":"end_turn"}');
        $factory = $this->createStub(CurlFactory::class);
        $factory->method('create')->willReturn($curl);
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([
            ['zero_cms/ai/api_key', 'default', null, 'test-key'],
            ['zero_ai_assistant/general/model', 'default', null, 'claude-haiku-4-5-20251001'],
            // A stand-in endpoint set in production: ignored, the key goes to Anthropic only.
            ['zero_ai_assistant/dev/api_url', 'default', null, 'https://stand-in.example/v1/messages'],
            ['zero_cms/ai/provider', 'default', null, 'anthropic'],
        ]);
        $state = $this->createStub(State::class);
        $state->method('getMode')->willReturn(State::MODE_PRODUCTION);
        $client = new Client(
            new MessagesApi($factory, $config, $this->createStub(EncryptorInterface::class), $this->createStub(LoggerInterface::class)),
            $config,
            $state
        );

        $client->send('Be brief.', [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'A'], ['type' => 'text', 'text' => 'B']]],
        ], [['name' => 'one', 'input_schema' => []], ['name' => 'two', 'input_schema' => []]]);

        $this->assertStringStartsWith('https://api.anthropic.com/', (string) $to);
        $cache = ['type' => 'ephemeral'];
        $this->assertSame('claude-haiku-4-5-20251001', $sent['model']);
        $this->assertSame([['type' => 'text', 'text' => 'Be brief.', 'cache_control' => $cache]], $sent['system']);
        $this->assertArrayNotHasKey('cache_control', $sent['tools'][0]);
        $this->assertSame($cache, $sent['tools'][1]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $sent['messages'][0]['content'][0]);
        $this->assertArrayNotHasKey('cache_control', $sent['messages'][2]['content'][0]);
        $this->assertSame($cache, $sent['messages'][2]['content'][1]['cache_control']);
    }
}
