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
 * Undo a confirmed change: the card, updated.
 */
class Undo extends AbstractChat implements HttpPostActionInterface
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
        $actionId = (int) $this->getRequest()->getParam('action_id');
        $userId = $this->chat->userId();
        $result = $this->agent->undo($actionId, $userId);
        return $this->partial('action.phtml', [
            'action' => $this->conversations->action($actionId, $userId) ?? [],
            'action_error' => in_array($result['status'], ['failed', 'not_undoable', 'busy'], true) ? (string) $result['result'] : null,
        ]);
    }
}
