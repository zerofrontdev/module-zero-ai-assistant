<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Bulk;

use Magento\Framework\Exception\LocalizedException;

/**
 * Bulk::undo refused because the job is still writing: it must be stopped (or end) first. A class of its own so a
 * caller (the Claude connector) can say where to stop it without reading the message.
 */
class StillWritingException extends LocalizedException
{
}
