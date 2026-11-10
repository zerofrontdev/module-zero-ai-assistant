<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Zero\AiAssistant\Model\Agent;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\ViewModel\Chat;

/**
 * Confirm (or, with cancel=1, cancel) a proposed change: the card, updated.
 */
class Confirm extends AbstractChat implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        Chat $chat,
        private readonly Agent $agent,
        private readonly Conversations $conversations
    ) {
        parent::__construct($context, $chat);
    }

    public function execute()
    {
        set_time_limit(300);
        $actionId = (int) $this->getRequest()->getParam('action_id');
        $userId = $this->chat->userId();
        if ($this->getRequest()->getParam('cancel')) {
            $this->agent->cancel($actionId, $userId);
        } else {
            $this->agent->confirm($actionId, $userId);
        }

        return $this->partial('action.phtml', ['action' => $this->conversations->action($actionId, $userId) ?? []]);
    }
}
