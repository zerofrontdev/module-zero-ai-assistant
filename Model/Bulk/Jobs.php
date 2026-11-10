<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Bulk;

use Magento\Framework\App\ResourceConnection;

/**
 * Bulk jobs (zero_ai_job) and their products (zero_ai_job_item). A job is
 * running until no product waits, then done; cancelled leaves the waiting
 * ones skipped; undoing puts the written ones back, then undone.
 */
class Jobs
{
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';
    public const UNDOING = 'undoing';
    public const UNDONE = 'undone';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param string[] $fields
     * @param array<int, array{id: int, sku: string, name: string}> $products
     * @return int[] the item ids
     */
    public function create(array $action, array $fields, string $brief, array $products): array
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $db->insert($this->table('zero_ai_job'), [
                'action_id' => (int) $action['action_id'],
                'conversation_id' => (int) $action['conversation_id'],
                'user_id' => (int) $action['user_id'],
                'fields' => json_encode(array_values($fields)),
                'brief' => $brief,
                'status' => self::RUNNING,
                'total' => count($products),
            ]);
            $jobId = (int) $db->lastInsertId($this->table('zero_ai_job'));
            $ids = [];
            foreach ($products as $product) {
                $db->insert($this->table('zero_ai_job_item'), ['job_id' => $jobId, 'product_id' => $product['id'], 'sku' => mb_substr($product['sku'], 0, 64)]);
                $ids[] = (int) $db->lastInsertId($this->table('zero_ai_job_item'));
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $ids;
    }

    public function get(int $jobId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table('zero_ai_job'))->where('job_id = ?', $jobId));
        return $row ? $this->decode($row) : null;
    }

    public function forAction(int $actionId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table('zero_ai_job'))->where('action_id = ?', $actionId));
        return $row ? $this->decode($row) : null;
    }

    public function item(int $itemId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table('zero_ai_job_item'))->where('item_id = ?', $itemId));
        if (!$row) {
            return null;
        }
        $row['before'] = (array) json_decode((string) $row['before'], true);
        $row['after'] = (array) json_decode((string) $row['after'], true);
        return $row;
    }

    /**
     * @return array<string, int> status => count
     */
    public function counts(int $jobId): array
    {
        $db = $this->resource->getConnection();
        return array_map('intval', $db->fetchPairs($db->select()
            ->from($this->table('zero_ai_job_item'), ['status', 'COUNT(*)'])
            ->where('job_id = ?', $jobId)
            ->group('status')));
    }

    /**
     * The last items written, for the card.
     *
     * @return array<int, array>
     */
    public function recent(int $jobId, int $limit = 5): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()
            ->from($this->table('zero_ai_job_item'), ['item_id', 'product_id', 'sku', 'status', 'after', 'error'])
            ->where('job_id = ?', $jobId)
            ->where('status IN (?)', ['done', 'failed'])
            ->order('updated_at DESC')
            ->order('item_id DESC')
            ->limit($limit));
        foreach ($rows as &$row) {
            $row['after'] = (array) json_decode((string) $row['after'], true);
        }
        return $rows;
    }

    /**
     * @param string|null $since only the items last changed at or after this time
     * @return int[] the ids of the job's items in $status
     */
    public function itemIds(int $jobId, string $status, ?string $since = null): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()
            ->from($this->table('zero_ai_job_item'), 'item_id')
            ->where('job_id = ?', $jobId)
            ->where('status = ?', $status);
        if ($since !== null) {
            $select->where('updated_at >= ?', $since);
        }
        return array_map('intval', $db->fetchCol($select));
    }

    /**
     * @param array<string, mixed> $fields status, before, after, error
     * @param string|null $from only when the item is still in this status
     * @return bool whether it changed
     */
    public function updateItem(int $itemId, array $fields, ?string $from = null): bool
    {
        foreach (['before', 'after'] as $json) {
            if (isset($fields[$json]) && is_array($fields[$json])) {
                $fields[$json] = json_encode($fields[$json], JSON_UNESCAPED_UNICODE);
            }
        }
        $where = ['item_id = ?' => $itemId];
        if ($from !== null) {
            $where['status = ?'] = $from;
        }
        return $this->resource->getConnection()->update($this->table('zero_ai_job_item'), $fields, $where) === 1;
    }

    public function setStatus(int $jobId, string $status, ?string $from = null): bool
    {
        $where = ['job_id = ?' => $jobId];
        if ($from !== null) {
            $where['status = ?'] = $from;
        }
        return $this->resource->getConnection()->update($this->table('zero_ai_job'), ['status' => $status], $where) === 1;
    }

    /**
     * Pending items skipped (Cancel).
     */
    public function skipPending(int $jobId): int
    {
        return $this->resource->getConnection()->update(
            $this->table('zero_ai_job_item'),
            ['status' => 'skipped'],
            ['job_id = ?' => $jobId, 'status = ?' => 'pending']
        );
    }

    private function decode(array $row): array
    {
        $row['fields'] = (array) json_decode((string) $row['fields'], true);
        $row['job_id'] = (int) $row['job_id'];
        $row['total'] = (int) $row['total'];
        return $row;
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
