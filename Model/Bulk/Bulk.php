<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Bulk;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Bulk jobs started, cancelled and undone: each product a message for the
 * consumer zero.ai.bulk (RabbitMQ when env.php has queue/amqp, the
 * database queue otherwise; cron starts the consumer).
 */
class Bulk
{
    public const TOPIC = 'zero.ai.bulk';
    public const MAX_PATH = 'zero_ai_assistant/bulk/max_products';

    public function __construct(
        private readonly Jobs $jobs,
        private readonly PublisherInterface $publisher,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function max(): int
    {
        return max(1, (int) ($this->scopeConfig->getValue(self::MAX_PATH) ?: 200));
    }

    /**
     * @param string[] $fields
     * @param array<int, array{id: int, sku: string, name: string}> $products
     */
    public function start(array $action, array $fields, string $brief, array $products): int
    {
        $itemIds = $this->jobs->create($action, $fields, $brief, $products);
        foreach ($itemIds as $itemId) {
            $this->publisher->publish(self::TOPIC, json_encode(['item_id' => $itemId, 'op' => 'write']));
        }
        return (int) $this->jobs->forAction((int) $action['action_id'])['job_id'];
    }

    public function cancel(array $job): void
    {
        if ($this->jobs->setStatus($job['job_id'], Jobs::CANCELLED, Jobs::RUNNING)) {
            $this->jobs->skipPending($job['job_id']);
        }
    }

    /**
     * The written products queued to get their old texts back. Taken once: of two
     * undos (Undo All twice, the chat and Claude at once) only one moves the job
     * from done or cancelled to undoing and queues the products.
     *
     * @return int the products queued; 0 when the job is not done or stopped
     * @throws StillWritingException while it's still writing
     * @throws LocalizedException while a product is being
     *     written, or when another undo took it first
     */
    public function undo(array $job): int
    {
        if ($job['status'] === Jobs::RUNNING) {
            throw new StillWritingException(__('It is still writing: cancel it first, or wait.'));
        }
        if (!in_array($job['status'], [Jobs::DONE, Jobs::CANCELLED], true)) {
            return 0;
        }
        if (($this->jobs->counts($job['job_id'])['working'] ?? 0) > 0) {
            throw new LocalizedException(__('One product is still being written: try again in a minute.'));
        }
        if (!$this->jobs->setStatus($job['job_id'], Jobs::UNDOING, (string) $job['status'])) {
            throw new LocalizedException(__('It is being undone already.'));
        }
        $itemIds = $this->jobs->itemIds($job['job_id'], 'done');
        foreach ($itemIds as $itemId) {
            $this->publisher->publish(self::TOPIC, json_encode(['item_id' => $itemId, 'op' => 'undo']));
        }
        if (!$itemIds) {
            $this->jobs->setStatus($job['job_id'], Jobs::UNDONE, Jobs::UNDOING);
        }
        return count($itemIds);
    }

    /**
     * An undoing job's products that became `done` at or after $since queued
     * for their undo again (the Reaper, for a job stuck undoing: a product a
     * consumer finished writing after Undo All queued the others, so it has
     * no undo message). $since is the job's updated_at before the Reaper took
     * it: the Undo All time first, then the previous reap, so products whose
     * undo is only waiting in the queue are not sent twice. A repeat that does
     * happen restores the same old texts again, so it is harmless.
     *
     * @return int the products queued; 0 when the job is not undoing
     */
    public function requeueUndo(array $job, string $since): int
    {
        if ($job['status'] !== Jobs::UNDOING) {
            return 0;
        }
        $itemIds = $this->jobs->itemIds((int) $job['job_id'], 'done', $since);
        foreach ($itemIds as $itemId) {
            $this->publisher->publish(self::TOPIC, json_encode(['item_id' => $itemId, 'op' => 'undo']));
        }
        return count($itemIds);
    }
}
