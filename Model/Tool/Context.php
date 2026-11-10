<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool;

use Magento\Framework\Exception\LocalizedException;
use Zero\AiAssistant\Model\Conversations;

/**
 * Whose conversation a tool works in, and its uploads.
 */
class Context
{
    public function __construct(
        public readonly int $conversationId,
        public readonly int $userId,
        private readonly Conversations $conversations,
        public readonly ?int $actionId = null
    ) {
    }

    /**
     * An image uploaded into this admin user's conversations.
     *
     * @throws LocalizedException
     */
    public function upload(int $uploadId): array
    {
        $upload = $this->conversations->upload($uploadId, $this->userId);
        if (!$upload) {
            throw new LocalizedException(__('There is no uploaded image #%1.', $uploadId));
        }

        return $upload;
    }
}
