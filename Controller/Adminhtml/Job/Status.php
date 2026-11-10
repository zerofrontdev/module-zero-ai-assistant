<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Controller\Adminhtml\Job;

use Magento\Framework\App\Action\HttpGetActionInterface;

/**
 * A bulk job's card, refreshed (HTMX polls it while it runs).
 */
class Status extends AbstractJob implements HttpGetActionInterface
{
    public function execute()
    {
        return $this->card();
    }
}
