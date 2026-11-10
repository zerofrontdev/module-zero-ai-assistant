<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Controller\Adminhtml\Job;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Raw;
use Zero\AiAssistant\Controller\Adminhtml\Chat\AbstractChat;
use Zero\AiAssistant\Model\Bulk\Bulk;
use Zero\AiAssistant\Model\Bulk\Jobs;
use Zero\AiAssistant\Model\Tools;
use Zero\AiAssistant\ViewModel\Chat;

/**
 * A bulk job of this admin user, while their role still has the tool. Unlike
 * the chat, the job's page and buttons work with Chat in the Admin off: a job
 * confirmed through the Claude connector is followed, stopped and undone here.
 */
abstract class AbstractJob extends AbstractChat
{
    public function __construct(
        Context $context,
        Chat $chat,
        protected readonly Jobs $jobs,
        protected readonly Bulk $bulk,
        private readonly Tools $tools
    ) {
        parent::__construct($context, $chat);
    }

    /**
     * The assistant's resource only, whatever the chat switch (job() checks the
     * job's owner and the role's bulk_write_products).
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(static::ADMIN_RESOURCE);
    }

    protected function job(): ?array
    {
        $job = $this->jobs->get((int) $this->getRequest()->getParam('job_id'));
        if ($job === null || (int) $job['user_id'] !== $this->chat->userId() || !$this->tools->get('bulk_write_products')) {
            return null;
        }
        return $job;
    }

    protected function card(?string $error = null): Raw
    {
        $job = $this->job();
        if ($job === null) {
            return $this->partial('job.phtml', []);
        }
        return $this->partial('job.phtml', [
            'job' => $this->jobData($job),
            'job_error' => $error,
        ]);
    }

    /**
     * What job.phtml shows: the job as it is now (re-read: a button may just have changed it), its counts and the last
     * products written.
     *
     * @return array{job: array, counts: array<string, int>, recent: array}
     */
    protected function jobData(array $job): array
    {
        $job = $this->jobs->get($job['job_id']) ?? $job;
        return ['job' => $job, 'counts' => $this->jobs->counts($job['job_id']), 'recent' => $this->jobs->recent($job['job_id'])];
    }
}
