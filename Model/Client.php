<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Zero\Cms\Model\Ai\MessagesApi;

/**
 * Claude's Messages API with tools (Anthropic API or Amazon Bedrock, per
 * Stores > Configuration > Zero > CMS > AI). The tools, system text and last
 * message are marked for prompt caching: a cached prefix costs a tenth.
 */
class Client
{
    private const MODEL_PATH = 'zero_ai_assistant/general/model';
    private const BEDROCK_MODEL_PATH = 'zero_ai_assistant/general/bedrock_model';
    /** Development only (not in the admin, ignored outside developer mode): another Messages API endpoint, e.g. a local stand-in for tests. */
    private const URL_PATH = 'zero_ai_assistant/dev/api_url';
    private const CACHE = ['type' => 'ephemeral'];

    public function __construct(
        private readonly MessagesApi $messagesApi,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly State $appState
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->messagesApi->isConfigured();
    }

    /**
     * One turn: the model's answer (content blocks, stop_reason).
     *
     * @throws LocalizedException
     */
    public function send(string $system, array $messages, array $tools, int $maxTokens = 4096): array
    {
        $body = [
            'max_tokens' => $maxTokens,
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => self::CACHE]],
            'messages' => $this->cached($messages),
        ];
        if ($tools) {
            $tools[count($tools) - 1]['cache_control'] = self::CACHE;
            $body['tools'] = $tools;
        }
        $model = $this->messagesApi->provider() === 'bedrock'
            ? (string) ($this->scopeConfig->getValue(self::BEDROCK_MODEL_PATH) ?: 'us.anthropic.claude-haiku-4-5-20251001-v1:0')
            : (string) ($this->scopeConfig->getValue(self::MODEL_PATH) ?: 'claude-haiku-4-5-20251001');

        // The stand-in endpoint only in developer mode: in production the key goes to Anthropic or Bedrock only.
        $url = $this->appState->getMode() === State::MODE_DEVELOPER ? ((string) $this->scopeConfig->getValue(self::URL_PATH) ?: null) : null;

        return $this->messagesApi->send($body, $model, $url);
    }

    /**
     * The conversation with a cache breakpoint on its last block: the next
     * request reads everything up to here from the cache.
     */
    private function cached(array $messages): array
    {
        $last = count($messages) - 1;
        if ($last >= 0 && is_array($messages[$last]['content'] ?? null) && $messages[$last]['content']) {
            $block = count($messages[$last]['content']) - 1;
            $messages[$last]['content'][$block]['cache_control'] = self::CACHE;
        }

        return $messages;
    }
}
