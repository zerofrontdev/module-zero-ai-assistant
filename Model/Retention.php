<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem;

/**
 * How long the assistant keeps what it was told and did (Stores > Configuration > Zero > AI Assistant > Keep Data
 * for, days; 0: for good), purged daily (Cron\Purge) or by bin/magento zero:ai:purge:
 * - messages, uploaded pictures (rows and files), finished changes (their Undo goes with them) and finished bulk jobs
 *   older than that; a change or job still running is left alone;
 * - a conversation once nothing is left in it, unless a live Claude connection still uses it (Zero_Mcp's tokens);
 * - the connector's revoked or expired connections older than that; its one-time codes, pending consents and drafts
 *   once they expire, and its registered clients that never got a connection after UNUSED_CLIENT_DAYS, whatever
 *   the setting.
 * The tables cascade: a conversation takes its messages, uploads and changes with it, a change its bulk job, a job its
 * products. So a conversation goes only once it is empty, and a finished change only while no job of it still runs.
 */
class Retention
{
    public const DAYS_PATH = 'zero_ai_assistant/general/keep_days';

    private const UPLOAD_DIR = 'zero_ai_assistant';
    private const FINISHED_ACTIONS = ['done', 'cancelled', 'failed', 'undone', 'expired'];
    private const FINISHED_JOBS = ['done', 'cancelled', 'undone'];
    private const BATCH = 1000;
    private const UNUSED_CLIENT_DAYS = 7;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * The days kept, 0 for good.
     */
    public function days(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::DAYS_PATH));
    }

    /**
     * Purges what is past its time.
     *
     * @param int|null $now a Unix time (tests), else the current one
     * @return array<string, int> what was removed, by kind
     */
    public function purge(?int $now = null): array
    {
        $now ??= time();
        $db = $this->resource->getConnection();
        $at = gmdate('Y-m-d H:i:s', $now);
        // Zero_Mcp's tables only while it is installed (a disabled module's tables are dropped by setup:upgrade).
        $mcp = $db->isTableExists($this->table('zero_mcp_token'));
        $removed = [];
        if ($mcp) {
            foreach (['mcp_codes' => 'zero_mcp_code', 'mcp_pending' => 'zero_mcp_pending', 'mcp_drafts' => 'zero_mcp_draft'] as $kind => $table) {
                $removed[$kind] = $db->delete($this->table($table), ['expires_at < ?' => $at]);
            }
            // Registered (anyone may register) but never connected: a client with a token (live or not), a pending
            // consent or a code (a sign-in under way) stays.
            $removed['mcp_clients'] = $db->delete($this->table('zero_mcp_client'), [
                'created_at < ?' => gmdate('Y-m-d H:i:s', $now - self::UNUSED_CLIENT_DAYS * 86400),
                'client_id NOT IN (?)' => $db->select()->union(array_map(
                    fn (string $table) => $db->select()->from($this->table($table), 'client_id'),
                    ['zero_mcp_token', 'zero_mcp_pending', 'zero_mcp_code']
                )),
            ]);
        }
        $days = $this->days();
        if ($days === 0) {
            return $removed;
        }
        $cutoff = gmdate('Y-m-d H:i:s', $now - $days * 86400);

        $removed['messages'] = $db->delete($this->table('zero_ai_message'), ['created_at < ?' => $cutoff]);
        $removed['uploads'] = $this->purgeUploads($db, $cutoff);
        // Jobs first (their products go with them), then the changes, none of which may still have a job running.
        $removed['bulk_jobs'] = $db->delete($this->table('zero_ai_job'), [
            'updated_at < ?' => $cutoff,
            'status IN (?)' => self::FINISHED_JOBS,
        ]);
        $removed['changes'] = $db->delete($this->table('zero_ai_action'), [
            'updated_at < ?' => $cutoff,
            'status IN (?)' => self::FINISHED_ACTIONS,
            'action_id NOT IN (?)' => $db->select()->from($this->table('zero_ai_job'), 'action_id')
                ->where('action_id IS NOT NULL')->where('status NOT IN (?)', self::FINISHED_JOBS),
        ]);
        if ($mcp) {
            $removed['connections'] = $db->delete($this->table('zero_mcp_token'), [
                '(revoked_at IS NOT NULL AND revoked_at < ?) OR refresh_expires_at < ?' => $cutoff,
            ]);
        }
        $removed['conversations'] = $this->purgeConversations($db, $cutoff, $at, $mcp);

        return $removed;
    }

    /**
     * Uploads older than the cutoff: the file, then the row.
     */
    private function purgeUploads(AdapterInterface $db, string $cutoff): int
    {
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $rows = $db->fetchPairs($db->select()->from($this->table('zero_ai_upload'), ['upload_id', 'file'])
            ->where('created_at < ?', $cutoff));
        foreach ($rows as $file) {
            $file = (string) $file;
            // Only files of the assistant's own folder: a row is data, not a path to trust.
            if (str_starts_with($file, self::UPLOAD_DIR . '/') && !str_contains($file, '..') && $var->isFile($file)) {
                $var->delete($file);
            }
        }
        $removed = 0;
        foreach (array_chunk(array_map('intval', array_keys($rows)), self::BATCH) as $ids) {
            $removed += $db->delete($this->table('zero_ai_upload'), ['upload_id IN (?)' => $ids]);
        }

        return $removed;
    }

    /**
     * Conversations last touched before the cutoff with nothing left in them (no message, upload, change or job) and
     * no live connection on them; their now empty upload folders go too.
     */
    private function purgeConversations(AdapterInterface $db, string $cutoff, string $now, bool $mcp): int
    {
        $conversation = $this->table('zero_ai_conversation');
        $select = $db->select()->from(['c' => $conversation], 'conversation_id')->where('c.updated_at < ?', $cutoff);
        foreach (['zero_ai_message', 'zero_ai_upload', 'zero_ai_action', 'zero_ai_job'] as $table) {
            $select->where('NOT EXISTS (?)', $db->select()->from(['x' => $this->table($table)], [new \Zend_Db_Expr('1')])
                ->where('x.conversation_id = c.conversation_id'));
        }
        if ($mcp) {
            $select->where('NOT EXISTS (?)', $db->select()->from(['t' => $this->table('zero_mcp_token')], [new \Zend_Db_Expr('1')])
                ->where('t.conversation_id = c.conversation_id')
                ->where('t.revoked_at IS NULL')
                ->where('t.refresh_expires_at > ?', $now));
        }
        $ids = array_map('intval', $db->fetchCol($select));
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $removed = 0;
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            $removed += $db->delete($conversation, ['conversation_id IN (?)' => $chunk]);
            foreach ($chunk as $id) {
                if ($var->isDirectory(self::UPLOAD_DIR . '/' . $id)) {
                    $var->delete(self::UPLOAD_DIR . '/' . $id);
                }
            }
        }

        return $removed;
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
