<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Cron;

use Psr\Log\LoggerInterface;
use Zero\AiAssistant\Model\Reaper;

/**
 * Every 15 minutes: changes and bulk products stuck for over an hour are marked failed, and bulk jobs stuck
 * undoing are queued again or settled (Model\Reaper).
 */
class Reap
{
    public function __construct(
        private readonly Reaper $reaper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $reaped = array_filter($this->reaper->reap());
            if ($reaped) {
                $this->logger->info('Zero_AiAssistant: settled after an hour stuck: ' . http_build_query($reaped, '', ', '));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Zero_AiAssistant: the reaper failed: ' . $e->getMessage());
        }
    }
}
