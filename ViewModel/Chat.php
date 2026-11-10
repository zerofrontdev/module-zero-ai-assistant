<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\ViewModel;

use Magento\Backend\Model\Auth\Session;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Zero\AiAssistant\Model\Client;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Markdown;
use Zero\AiAssistant\Model\Tools;

/**
 * The chat page: the admin user's conversations, one conversation as items
 * to show, what the assistant can do for this user.
 */
class Chat implements ArgumentInterface
{
    public const ADMIN_CHAT_PATH = 'zero_ai_assistant/general/admin_chat';

    public function __construct(
        private readonly Session $session,
        private readonly Conversations $conversations,
        private readonly Tools $tools,
        private readonly Markdown $markdown,
        private readonly Client $client,
        private readonly UrlInterface $url,
        private readonly FormKey $formKey,
        private readonly \Magento\Backend\Model\Locale\Resolver $localeResolver,
        private readonly \Zero\AiAssistant\Model\Commands $commands,
        private readonly \Zero\AiAssistant\Model\Bulk\Jobs $jobs,
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Chat in the Admin (Zero > AI Assistant). Off: no page and no menu item;
     * the assistant stays reachable through the Claude connector (Zero_Mcp).
     */
    public function isAdminChatOn(): bool
    {
        return $this->scopeConfig->isSetFlag(self::ADMIN_CHAT_PATH);
    }

    /**
     * The admin user's interface language for dictation (uk-UA, en-US…).
     */
    public function speechLanguage(): string
    {
        return str_replace('_', '-', (string) $this->localeResolver->getLocale());
    }

    public function userId(): int
    {
        return (int) $this->session->getUser()?->getId();
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function conversations(): array
    {
        return $this->conversations->recent($this->userId());
    }

    public function conversation(int $id): ?array
    {
        return $id ? $this->conversations->get($id, $this->userId()) : null;
    }

    public function formKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function url(string $route, array $params = []): string
    {
        return $this->url->getUrl($route, $params);
    }

    /**
     * What it can do here, by the role: [label, description, changes].
     */
    public function capabilities(): array
    {
        $list = [];
        foreach ($this->tools->allowed() as $tool) {
            $list[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'changes' => $tool->changes(),
            ];
        }

        return $list;
    }

    /**
     * The conversation to show: user messages (text, images), the assistant's
     * answers (HTML), the tools it used, the changes it proposed, notes.
     *
     * @return array<array{kind: string}>
     */
    public function items(int $conversationId): array
    {
        if (!$this->conversation($conversationId)) {
            return [];
        }
        $messages = $this->conversations->messages($conversationId);
        $actions = $this->conversations->actions($conversationId);
        $changeByToolUse = [];
        foreach ($messages as $message) {
            foreach ($message['content'] as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    $data = json_decode((string) ($block['content'] ?? ''), true);
                    if (is_array($data) && isset($data['change_id'], $actions[(int) $data['change_id']])) {
                        $changeByToolUse[$block['tool_use_id']] = $actions[(int) $data['change_id']];
                    } elseif (!empty($block['is_error'])) {
                        $changeByToolUse[$block['tool_use_id']] = ['error' => (string) $block['content']];
                    }
                }
            }
        }
        $items = [];
        $summary = $this->conversations->summary($conversationId);
        $marked = false;
        foreach ($messages as $message) {
            if ($summary !== null && !$marked && $message['message_id'] > $summary['upto']) {
                $items[] = ['kind' => 'compacted'];
                $marked = true;
            }
            if ($message['role'] === 'user') {
                $images = [];
                $text = '';
                foreach ($message['content'] as $block) {
                    if (($block['type'] ?? '') === 'zero_upload') {
                        $images[] = (int) $block['upload_id'];
                    } elseif (($block['type'] ?? '') === 'text') {
                        if (str_starts_with((string) $block['text'], '[The admin')) {
                            $items[] = ['kind' => 'note', 'text' => trim((string) $block['text'], '[]')];
                        } else {
                            $text .= (string) $block['text'];
                        }
                    }
                }
                if ($images || $text !== '') {
                    $items[] = ['kind' => 'user', 'text' => $text, 'images' => $images];
                }
                continue;
            }
            foreach ($message['content'] as $block) {
                if (($block['type'] ?? '') === 'text' && trim((string) $block['text']) !== '') {
                    $items[] = ['kind' => 'assistant', 'html' => $this->markdown->toHtml((string) $block['text'])];
                } elseif (($block['type'] ?? '') === 'tool_use') {
                    $change = $changeByToolUse[$block['id']] ?? null;
                    if (isset($change['action_id'])) {
                        $items[] = ['kind' => 'action', 'action' => $change];
                    } else {
                        $items[] = ['kind' => 'tool', 'name' => (string) $block['name'], 'error' => $change['error'] ?? null];
                    }
                }
            }
        }

        return $items;
    }

    /**
     * A finished change's result as label => value lines (edit link apart).
     */
    public function result(array $action): array
    {
        $result = json_decode((string) ($action['result'] ?? ''), true);

        return is_array($result) ? $result : ['message' => (string) ($action['result'] ?? '')];
    }

    /**
     * The commands this role has, for the message field's list.
     *
     * @return array<string, array{label: string, text: string, example: string}>
     */
    public function commands(): array
    {
        return $this->commands->allowed();
    }

    /**
     * A bulk change's job: its status, the counts, the last products written.
     *
     * @return array{job: array, counts: array<string, int>, recent: array}|null
     */
    public function job(array $action): ?array
    {
        if (($action['tool'] ?? '') !== 'bulk_write_products' || ($action['status'] ?? '') !== 'done') {
            return null;
        }
        $job = $this->jobs->forAction((int) $action['action_id']);
        if ($job === null || (int) $job['user_id'] !== $this->userId()) {
            return null;
        }
        return ['job' => $job, 'counts' => $this->jobs->counts($job['job_id']), 'recent' => $this->jobs->recent($job['job_id'])];
    }

    public function canUndo(array $action): bool
    {
        return ($action['status'] ?? '') === 'done' && !empty($action['undo']);
    }
}
