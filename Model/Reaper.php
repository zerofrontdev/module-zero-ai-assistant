<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model;

use Magento\Framework\App\ResourceConnection;
use Zero\AiAssistant\Model\Bulk\Bulk;
use Zero\AiAssistant\Model\Bulk\Consumer;
use Zero\AiAssistant\Model\Bulk\Jobs;

/**
 * Changes and bulk products stuck in the middle (a PHP process killed, a timeout, a deploy) marked failed once they
 * have not moved for AFTER seconds (Cron\Reap, every 15 minutes):
 * - a change still running: failed, with a note in its conversation;
 * - a bulk product still being written: failed, and its job settled (done once nothing waits);
 * - a bulk job still undoing: its products left `done` queued for their undo again (one written after Undo All
 *   queued the others), or the job settled (undone) when none is left. Taken by a compare-and-set on the job's
 *   updated_at, which it moves to now, so it is tried again an hour later at most.
 * Each row is taken with a compare-and-set on its status, so a run is idempotent and a row that finished meanwhile
 * is left alone. A tool genuinely running longer than that overwrites the failed status with its own outcome when it
 * ends (accepted: the threshold is far above the web and queue timeouts).
 */
class Reaper
{
    public const AFTER = 3600;
    private const ACTIONS = 500;
    private const ITEMS = 1000;
    private const UNDOS = 100;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Conversations $conversations,
        private readonly Jobs $jobs,
        private readonly Consumer $consumer,
        private readonly Bulk $bulk
    ) {
    }

    /**
     * @param int|null $now a Unix time (tests), else the current one
     * @return array{changes: int, items: int, undos: int} what was marked failed, and the undoing jobs taken
     */
    public function reap(?int $now = null): array
    {
        $now ??= time();
        $cutoff = gmdate('Y-m-d H:i:s', $now - self::AFTER);
        $message = (string) __('It stopped without finishing (over an hour): check what it changed before trying again.');

        return [
            'changes' => $this->reapChanges($cutoff, $message),
            'items' => $this->reapItems($cutoff, $message),
            'undos' => $this->reapUndos($cutoff, gmdate('Y-m-d H:i:s', $now)),
        ];
    }

    private function reapChanges(string $cutoff, string $message): int
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('zero_ai_action');
        $rows = $db->fetchAll($db->select()
            ->from($table, ['action_id', 'conversation_id', 'tool'])
            ->where('status = ?', 'running')
            ->where('updated_at < ?', $cutoff)
            ->limit(self::ACTIONS));
        $reaped = 0;
        foreach ($rows as $row) {
            $taken = $db->update(
                $table,
                ['status' => 'failed', 'result' => $message],
                ['action_id = ?' => (int) $row['action_id'], 'status = ?' => 'running']
            );
            if ($taken !== 1) {
                continue; // finished meanwhile
            }
            $reaped++;
            $this->conversations->addMessage((int) $row['conversation_id'], 'user', [['type' => 'text', 'text' => sprintf(
                '[The admin\'s change #%d (%s) stopped without finishing and is marked failed.]',
                (int) $row['action_id'],
                (string) $row['tool']
            )]]);
        }

        return $reaped;
    }

    private function reapItems(string $cutoff, string $message): int
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()
            ->from($this->resource->getTableName('zero_ai_job_item'), ['item_id', 'job_id'])
            ->where('status = ?', 'working')
            ->where('updated_at < ?', $cutoff)
            ->limit(self::ITEMS));
        $reaped = 0;
        $jobIds = [];
        foreach ($rows as $row) {
            if ($this->jobs->updateItem((int) $row['item_id'], ['status' => 'failed', 'error' => $message], 'working')) {
                $reaped++;
                $jobIds[(int) $row['job_id']] = true;
            }
        }
        foreach (array_keys($jobIds) as $jobId) {
            $job = $this->jobs->get($jobId);
            if ($job !== null) {
                $this->consumer->finish($job);
            }
        }

        return $reaped;
    }

    /**
     * Bulk jobs undoing for over AFTER seconds: their `done` products with no undo message (done after Undo All, or
     * after the previous reap) queued for the undo again, or the job settled when no product is left `done`.
     */
    private function reapUndos(string $cutoff, string $now): int
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('zero_ai_job');
        $rows = $db->fetchAll($db->select()
            ->from($table, ['job_id', 'updated_at'])
            ->where('status = ?', Jobs::UNDOING)
            ->where('updated_at < ?', $cutoff)
            ->limit(self::UNDOS));
        $taken = 0;
        foreach ($rows as $row) {
            $claimed = $db->update(
                $table,
                ['updated_at' => $now],
                ['job_id = ?' => (int) $row['job_id'], 'status = ?' => Jobs::UNDOING, 'updated_at = ?' => $row['updated_at']]
            );
            if ($claimed !== 1) {
                continue; // settled, or taken by another run, meanwhile
            }
            $job = $this->jobs->get((int) $row['job_id']);
            if ($job === null) {
                continue;
            }
            $taken++;
            if ($this->bulk->requeueUndo($job, (string) $row['updated_at']) === 0) {
                $this->consumer->finish($job);
            }
        }

        return $taken;
    }
}
