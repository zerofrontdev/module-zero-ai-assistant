<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool;

use Magento\Framework\Exception\LocalizedException;

/**
 * A changing tool whose change can be undone: snapshot() keeps what the
 * change will overwrite (taken just before it runs), restore() puts it back.
 */
interface ReversibleInterface
{
    /**
     * @return array<string, mixed> what Undo needs
     */
    public function snapshot(array $input, Context $context): array;

    /**
     * @return array<string, mixed> the answer for Claude and the card
     * @throws LocalizedException
     */
    public function restore(array $snapshot, Context $context): array;
}
