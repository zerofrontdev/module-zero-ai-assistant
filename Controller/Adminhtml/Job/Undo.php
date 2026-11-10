<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Controller\Adminhtml\Job;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Undo a bulk job: every product it wrote gets its old texts back (in the background).
 */
class Undo extends AbstractJob implements HttpPostActionInterface
{
    public function execute()
    {
        $job = $this->job();
        $error = null;
        if ($job) {
            try {
                $this->bulk->undo($job);
            } catch (LocalizedException $e) {
                $error = $e->getMessage();
            }
        }
        return $this->card($error);
    }
}
