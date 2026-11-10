<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Bulk;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zero\AiAssistant\Model\Acl\UserAuthorizationFactory;
use Zero\AiAssistant\Model\ToolsFactory;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Tool\Catalog\ProductWriter;
use Zero\AiAssistant\Model\Tool\Context;

/**
 * The consumer zero.ai.bulk: one product of a bulk job per message.
 * - write: the texts asked for written (Writer), the old ones kept for Undo;
 * - undo: the old ones put back.
 * The job ends (and says so in its conversation) when no product waits.
 * A product that fails is marked so, with the reason; the others go on.
 */
class Consumer
{
    public function __construct(
        private readonly Jobs $jobs,
        private readonly Writer $writer,
        private readonly ProductWriter $productWriter,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Conversations $conversations,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly UserAuthorizationFactory $authorizationFactory,
        private readonly ToolsFactory $toolsFactory
    ) {
    }

    /**
     * Whether the job's admin user may still write products in bulk: active, and the role still has the AI
     * Assistant and bulk_write_products (its resource and, for a role limited to areas, catalog changes). Checked
     * for every product, as the chat checks on Confirm: a role changed while the job runs stops it.
     */
    private function mayWrite(int $userId): bool
    {
        $authorization = $this->authorizationFactory->create(['userId' => $userId]);

        return $authorization->isAllowed('Zero_AiAssistant::chat')
            && $this->toolsFactory->create(['authorization' => $authorization])->get('bulk_write_products') !== null;
    }

    /**
     * @param string $body {"item_id": 12, "op": "write"|"undo"}
     */
    public function process(string $body): void
    {
        $message = (array) json_decode($body, true);
        $item = $this->jobs->item((int) ($message['item_id'] ?? 0));
        if ($item === null) {
            return;
        }
        $job = $this->jobs->get((int) $item['job_id']);
        if ($job === null) {
            return;
        }
        try {
            if (!$this->mayWrite((int) $job['user_id'])) {
                throw new LocalizedException(__('The admin user who confirmed this job may not change products in bulk any more.'));
            }
            if (($message['op'] ?? 'write') === 'undo') {
                $this->undo($job, $item);
            } else {
                $this->write($job, $item);
            }
        } catch (\Throwable $e) {
            if (!$e instanceof LocalizedException) {
                $this->logger->error('Zero AI Assistant: bulk job #' . $job['job_id'] . ', ' . $item['sku'] . ': ' . $e);
            }
            $this->jobs->updateItem((int) $item['item_id'], ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
        $this->finish($job);
    }

    private function write(array $job, array $item): void
    {
        if ($job['status'] !== Jobs::RUNNING || !$this->jobs->updateItem((int) $item['item_id'], ['status' => 'working'], 'pending')) {
            return; // cancelled, or taken already
        }
        $this->storeManager->setCurrentStore(0);
        $product = $this->productRepository->get((string) $item['sku'], true, 0, true);
        $before = [];
        foreach ($job['fields'] as $field) {
            $before[$field] = (string) $product->getData($field);
        }
        $texts = $this->writer->write($product, $job['fields'], (string) $job['brief']);
        $this->productWriter->update(['sku' => $item['sku']] + $texts, $this->context($job));
        $this->jobs->updateItem((int) $item['item_id'], ['status' => 'done', 'before' => $before, 'after' => $texts, 'error' => null]);
    }

    private function undo(array $job, array $item): void
    {
        if ($job['status'] !== Jobs::UNDOING || $item['status'] !== 'done' || !$item['before']) {
            return;
        }
        $this->productWriter->restore(['sku' => $item['sku']] + array_map('strval', $item['before']), $this->context($job));
        $this->jobs->updateItem((int) $item['item_id'], ['status' => 'undone'], 'done');
    }

    /**
     * The job over once nothing waits: done (or undone), said in its conversation.
     * Public for the Reaper, which settles a job whose stuck products it failed.
     */
    public function finish(array $job): void
    {
        $counts = $this->jobs->counts($job['job_id']);
        if ($job['status'] === Jobs::RUNNING && empty($counts['pending']) && empty($counts['working'])) {
            if ($this->jobs->setStatus($job['job_id'], Jobs::DONE, Jobs::RUNNING)) {
                $this->note($job, sprintf(
                    '[The admin\'s bulk job #%d (change #%d) finished: %d written, %d failed.]',
                    $job['job_id'],
                    $job['action_id'],
                    $counts['done'] ?? 0,
                    $counts['failed'] ?? 0
                ));
            }
        } elseif ($job['status'] === Jobs::UNDOING && empty($counts['done'])) {
            if ($this->jobs->setStatus($job['job_id'], Jobs::UNDONE, Jobs::UNDOING)) {
                $this->note($job, sprintf('[The admin undid bulk job #%d (change #%d): %d products have their old texts back.]', $job['job_id'], $job['action_id'], $counts['undone'] ?? 0));
            }
        }
    }

    private function note(array $job, string $text): void
    {
        $this->conversations->addMessage((int) $job['conversation_id'], 'user', [['type' => 'text', 'text' => $text]]);
    }

    private function context(array $job): Context
    {
        return new Context((int) $job['conversation_id'], (int) $job['user_id'], $this->conversations);
    }
}
