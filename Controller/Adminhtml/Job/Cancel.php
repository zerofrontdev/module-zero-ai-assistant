<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Controller\Adminhtml\Job;

use Magento\Framework\App\Action\HttpPostActionInterface;

/**
 * Stop a bulk job: the products not written yet are skipped.
 */
class Cancel extends AbstractJob implements HttpPostActionInterface
{
    public function execute()
    {
        $job = $this->job();
        if ($job) {
            $this->bulk->cancel($job);
        }
        return $this->card();
    }
}
