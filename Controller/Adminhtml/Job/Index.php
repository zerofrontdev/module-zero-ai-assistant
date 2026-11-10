<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Controller\Adminhtml\Job;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * A bulk job's own page (zero_ai/job/index/job_id/N): its progress, Stop and Undo All, also with Chat in the Admin
 * off. The Claude connector links here, so it opens without the URL's secret key (it only shows; Stop and Undo All
 * post with the form key), and after the admin's sign-in (Zero_Mcp's AdminDeepLink).
 */
class Index extends AbstractJob implements HttpGetActionInterface
{
    public function execute()
    {
        $jobId = (int) $this->getRequest()->getParam('job_id');
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->getConfig()->getTitle()->prepend(__('Bulk job #%1', $jobId));
        $block = $page->getLayout()->getBlock('zero.ai.job');
        if ($block) {
            $job = $this->job();
            $block->setData('job_id', $jobId);
            $block->setData('job', $job !== null ? $this->jobData($job) : null);
        }

        return $page;
    }

    /**
     * @inheritDoc
     */
    protected function _validateSecretKey()
    {
        return true;
    }
}
