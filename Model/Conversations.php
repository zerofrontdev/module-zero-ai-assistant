<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * The assistant's tables: conversations, messages, uploads, proposed changes.
 * Everything is looked up by its admin user too: one sees one's own only.
 */
class Conversations
{
    /**
     * How long a proposed change can still be confirmed (seconds).
     */
    public const PROPOSAL_TTL = 86400;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function create(int $userId, string $title): int
    {
        $this->connection()->insert($this->table('zero_ai_conversation'), [
            'user_id' => $userId,
            'title' => mb_substr($title, 0, 255),
        ]);

        return (int) $this->connection()->lastInsertId($this->table('zero_ai_conversation'));
    }

    public function get(int $conversationId, int $userId): ?array
    {
        $row = $this->connection()->fetchRow($this->connection()->select()
            ->from($this->table('zero_ai_conversation'))
            ->where('conversation_id = ?', $conversationId)
            ->where('user_id = ?', $userId));

        return $row ?: null;
    }

    public function recent(int $userId, int $limit = 30): array
    {
        return $this->connection()->fetchAll($this->connection()->select()
            ->from($this->table('zero_ai_conversation'))
            ->where('user_id = ?', $userId)
            ->order('updated_at DESC')
            ->limit($limit));
    }

    /**
     * @return array{summary: string, upto: int}|null what the older messages said, and the last one it covers
     */
    public function summary(int $conversationId): ?array
    {
        $row = $this->connection()->fetchRow($this->connection()->select()
            ->from($this->table('zero_ai_conversation'), ['summary', 'summary_upto'])
            ->where('conversation_id = ?', $conversationId));
        if (!$row || $row['summary'] === null || $row['summary_upto'] === null) {
            return null;
        }
        return ['summary' => (string) $row['summary'], 'upto' => (int) $row['summary_upto']];
    }

    public function saveSummary(int $conversationId, string $summary, int $upto): void
    {
        $this->connection()->update(
            $this->table('zero_ai_conversation'),
            ['summary' => $summary, 'summary_upto' => $upto],
            ['conversation_id = ?' => $conversationId]
        );
    }

    public function touch(int $conversationId): void
    {
        $this->connection()->update(
            $this->table('zero_ai_conversation'),
            ['updated_at' => new \Zend_Db_Expr('CURRENT_TIMESTAMP')],
            ['conversation_id = ?' => $conversationId]
        );
    }

    public function addMessage(int $conversationId, string $role, array $content): void
    {
        $this->connection()->insert($this->table('zero_ai_message'), [
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => (string) json_encode($content, JSON_UNESCAPED_UNICODE),
        ]);
        $this->touch($conversationId);
    }

    /**
     * @return array<array{message_id: int, role: string, content: array}>
     */
    public function messages(int $conversationId): array
    {
        $rows = $this->connection()->fetchAll($this->connection()->select()
            ->from($this->table('zero_ai_message'))
            ->where('conversation_id = ?', $conversationId)
            ->order('message_id ASC'));

        return array_map(static fn (array $row) => [
            'message_id' => (int) $row['message_id'],
            'role' => (string) $row['role'],
            'content' => (array) json_decode((string) $row['content'], true),
        ], $rows);
    }

    public function addUpload(int $conversationId, string $file, string $name, string $mime): int
    {
        $this->connection()->insert($this->table('zero_ai_upload'), [
            'conversation_id' => $conversationId,
            'file' => $file,
            'name' => mb_substr($name, 0, 255),
            'mime' => $mime,
        ]);

        return (int) $this->connection()->lastInsertId($this->table('zero_ai_upload'));
    }

    /**
     * An upload of one of the user's conversations.
     */
    /**
     * An upload by its conversation (the Claude connector's photo page,
     * which knows the draft's conversation, not a user).
     */
    public function uploadOfConversation(int $uploadId, int $conversationId): ?array
    {
        $row = $this->connection()->fetchRow($this->connection()->select()
            ->from($this->table('zero_ai_upload'))
            ->where('upload_id = ?', $uploadId)
            ->where('conversation_id = ?', $conversationId));
        return $row ?: null;
    }

    public function upload(int $uploadId, int $userId): ?array
    {
        $row = $this->connection()->fetchRow($this->connection()->select()
            ->from(['u' => $this->table('zero_ai_upload')])
            ->join(['c' => $this->table('zero_ai_conversation')], 'c.conversation_id = u.conversation_id', [])
            ->where('u.upload_id = ?', $uploadId)
            ->where('c.user_id = ?', $userId));

        return $row ?: null;
    }

    public function propose(int $conversationId, int $userId, string $tool, array $input, array $preview): int
    {
        $this->connection()->insert($this->table('zero_ai_action'), [
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'tool' => $tool,
            'input' => (string) json_encode($input, JSON_UNESCAPED_UNICODE),
            'preview' => (string) json_encode($preview, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->connection()->lastInsertId($this->table('zero_ai_action'));
    }

    public function action(int $actionId, int $userId): ?array
    {
        $row = $this->connection()->fetchRow($this->connection()->select()
            ->from($this->table('zero_ai_action'))
            ->where('action_id = ?', $actionId)
            ->where('user_id = ?', $userId));
        if (!$row) {
            return null;
        }
        return $this->decode($row);
    }

    /**
     * The conversation's proposed changes, by id.
     */
    public function actions(int $conversationId): array
    {
        $rows = $this->connection()->fetchAll($this->connection()->select()
            ->from($this->table('zero_ai_action'))
            ->where('conversation_id = ?', $conversationId));
        $actions = [];
        foreach ($rows as $row) {
            $actions[(int) $row['action_id']] = $this->decode($row);
        }

        return $actions;
    }

    /**
     * The conversation's newest changes, newest first, at most $limit.
     */
    public function recentActions(int $conversationId, int $limit): array
    {
        $rows = $this->connection()->fetchAll($this->connection()->select()
            ->from($this->table('zero_ai_action'))
            ->where('conversation_id = ?', $conversationId)
            ->order('action_id DESC')
            ->limit(max(1, $limit)));

        return array_map(fn (array $row) => $this->decode($row), $rows);
    }

    private function decode(array $row): array
    {
        $row['input'] = (array) json_decode((string) $row['input'], true);
        $row['preview'] = (array) json_decode((string) $row['preview'], true);
        $row['undo'] = isset($row['undo']) && $row['undo'] !== null && $row['undo'] !== '' ? (array) json_decode((string) $row['undo'], true) : null;

        return $row;
    }

    public function setUndo(int $actionId, array $undo): void
    {
        $this->connection()->update(
            $this->table('zero_ai_action'),
            ['undo' => (string) json_encode($undo, JSON_UNESCAPED_UNICODE)],
            ['action_id = ?' => $actionId]
        );
    }

    /**
     * Takes a proposed change for one decision: of two confirms (a double
     * click, the chat and Claude at once) only one gets it. A proposal
     * older than PROPOSAL_TTL is not taken: it becomes "expired".
     *
     * @param string $status what it becomes: running (being confirmed) or cancelled
     */
    public function claim(int $actionId, int $userId, string $status): bool
    {
        $where = ['action_id = ?' => $actionId, 'user_id = ?' => $userId, 'status = ?' => 'proposed'];
        $taken = $this->connection()->update(
            $this->table('zero_ai_action'),
            ['status' => $status],
            $where + ['created_at >= ?' => gmdate('Y-m-d H:i:s', time() - self::PROPOSAL_TTL)]
        );
        if ($taken === 1) {
            return true;
        }
        $this->connection()->update($this->table('zero_ai_action'), ['status' => 'expired', 'result' => (string) __('Not confirmed within a day: propose it again.')], $where);
        return false;
    }

    public function finish(int $actionId, string $status, string $result): void
    {
        $this->connection()->update(
            $this->table('zero_ai_action'),
            ['status' => $status, 'result' => $result],
            ['action_id = ?' => $actionId]
        );
    }

    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
