<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\System;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Indexer\Model\Indexer\CollectionFactory as IndexerCollectionFactory;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;

/**
 * store_status: the store's health at a glance, as Cache Management, Index
 * Management and the cron table show it.
 */
class Status extends ReadTool
{
    public function __construct(
        private readonly State $state,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly TypeListInterface $cacheTypes,
        private readonly IndexerCollectionFactory $indexers,
        private readonly ResourceConnection $resource
    ) {
    }

    public function name(): string
    {
        return 'store_status';
    }

    public function description(): string
    {
        return 'The store\'s health: mode, maintenance, every cache type (enabled, invalidated), every index (mode, valid or invalid, backlog of a scheduled one), cron (last run, pending, recent errors), the message queue backlog. Say what needs attention: an invalidated cache wants flush_cache, an invalid index reindex, cron silent for over 15 minutes is a server matter. Read only.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function resource(): string
    {
        return 'Magento_Backend::cache';
    }

    public function area(): string
    {
        return 'system';
    }

    public function run(array $input, Context $context): array
    {
        return [
            'mode' => $this->state->getMode(),
            'maintenance' => $this->maintenanceMode->isOn(),
            'caches' => $this->caches(),
            'indexes' => $this->indexes(),
            'cron' => $this->cron(),
            'queue' => $this->queue(),
            'time' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s') . ' UTC',
        ];
    }

    private function caches(): array
    {
        $invalidated = array_keys($this->cacheTypes->getInvalidated());
        $caches = [];
        foreach ($this->cacheTypes->getTypes() as $code => $type) {
            $caches[] = [
                'code' => (string) $code,
                'label' => (string) $type->getCacheType(),
                'enabled' => (bool) $type->getStatus(),
                'invalidated' => in_array($code, $invalidated, true),
            ];
        }

        return $caches;
    }

    private function indexes(): array
    {
        $indexes = [];
        foreach ($this->indexers->create()->getItems() as $indexer) {
            $row = [
                'id' => $indexer->getId(),
                'title' => (string) $indexer->getTitle(),
                'mode' => $indexer->isScheduled() ? 'schedule' : 'save',
                'status' => $indexer->isWorking() ? 'working' : ($indexer->isValid() ? 'valid' : 'invalid'),
                'updated' => (string) $indexer->getLatestUpdated(),
            ];
            if ($indexer->isScheduled()) {
                try {
                    $view = $indexer->getView();
                    $row['backlog'] = max(0, (int) $view->getChangelog()->getVersion() - (int) $view->getState()->getVersionId());
                } catch (\Throwable) {
                    $row['backlog'] = null;
                }
            }
            $indexes[] = $row;
        }

        return $indexes;
    }

    private function cron(): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('cron_schedule');
        $lastSuccess = $connection->fetchOne($connection->select()->from($table, ['executed_at'])->where('status = ?', 'success')->order('executed_at DESC')->limit(1));
        $pending = (int) $connection->fetchOne($connection->select()->from($table, ['COUNT(*)'])->where('status = ?', 'pending'));
        $errors = $connection->fetchAll($connection->select()->from($table, ['job_code', 'messages', 'executed_at'])
            ->where('status IN (?)', ['error', 'missed'])->where('scheduled_at > ?', (new \DateTimeImmutable('-24 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'))
            ->order('scheduled_at DESC')->limit(5));
        $minutes = $lastSuccess ? (int) floor(((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp() - (new \DateTimeImmutable((string) $lastSuccess, new \DateTimeZone('UTC')))->getTimestamp()) / 60) : null;

        return [
            'last_success' => $lastSuccess ? (string) $lastSuccess . ' UTC' : null,
            'minutes_since_last_success' => $minutes,
            'running' => $minutes !== null && $minutes <= 15,
            'pending_jobs' => $pending,
            'errors_24h' => array_map(static fn (array $e) => ['job' => $e['job_code'], 'message' => mb_substr((string) $e['messages'], 0, 200), 'at' => $e['executed_at']], $errors),
        ];
    }

    private function queue(): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('queue_message_status');
        if (!$connection->isTableExists($table)) {
            return ['backlog' => null];
        }
        // 2 = new, 5 = retry required, 6 = error (Magento\MysqlMq\Model\QueueMessage).
        $counts = $connection->fetchPairs($connection->select()->from($table, ['status', 'COUNT(*)'])->where('status IN (?)', [2, 5, 6])->group('status'));

        return ['backlog' => (int) ($counts[2] ?? 0), 'retrying' => (int) ($counts[5] ?? 0), 'errors' => (int) ($counts[6] ?? 0)];
    }
}
