<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Cron;

use Psr\Log\LoggerInterface;
use Zero\AiAssistant\Model\Retention;

/**
 * Daily: what the assistant keeps past its time is purged (Model\Retention).
 */
class Purge
{
    public function __construct(
        private readonly Retention $retention,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $removed = array_filter($this->retention->purge());
            if ($removed) {
                $this->logger->info('Zero_AiAssistant: purged ' . http_build_query($removed, '', ', '));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Zero_AiAssistant: the purge failed: ' . $e->getMessage());
        }
    }
}
