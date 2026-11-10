<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Zero\AiAssistant\Model\Agent;
use Zero\AiAssistant\Model\Commands;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Uploads;
use Zero\AiAssistant\ViewModel\Chat;

/**
 * The admin's message (text and images): Claude's turn, then the whole
 * conversation, a fresh message form and the conversation list (HTMX).
 */
class Send extends AbstractChat implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        Chat $chat,
        private readonly Agent $agent,
        private readonly Conversations $conversations,
        private readonly Uploads $uploads,
        private readonly Session $session,
        private readonly Commands $commands
    ) {
        parent::__construct($context, $chat);
    }

    public function execute()
    {
        // A turn with tools can take a while.
        set_time_limit(300);
        $user = $this->session->getUser();
        $userId = (int) $user->getId();
        $text = $this->commands->expand(trim((string) $this->getRequest()->getParam('message')));
        $files = array_filter(
            (array) ($this->getRequest()->getFiles('images') ?? []),
            static fn ($file) => is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        );
        $conversationId = (int) $this->getRequest()->getParam('conversation_id');
        $error = null;
        if ($text === '' && !$files) {
            return $this->partial('thread.phtml', ['conversation_id' => $conversationId, 'oob' => true]);
        }
        try {
            if (!$this->chat->conversation($conversationId)) {
                $conversationId = $this->conversations->create($userId, $text !== '' ? $text : (string) __('Images'));
            }
            $uploadIds = [];
            foreach (array_slice($files, 0, 10) as $file) {
                if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
                    throw new LocalizedException(__('%1 didn\'t upload (too large?).', (string) ($file['name'] ?? '')));
                }
                $uploadIds[] = $this->uploads->store($conversationId, (string) $file['tmp_name'], (string) $file['name']);
            }
            $this->agent->send($conversationId, $userId, trim($user->getFirstName() . ' ' . $user->getLastName()), $text, $uploadIds);
        } catch (LocalizedException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            $this->_objectManager->get(\Psr\Log\LoggerInterface::class)->error('Zero AI Assistant: ' . $e);
            $error = (string) __('Something went wrong: the details are in var/log/system.log.');
        }

        return $this->partial('thread.phtml', ['conversation_id' => $conversationId, 'error' => $error, 'oob' => true]);
    }
}
