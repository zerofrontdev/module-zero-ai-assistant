<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Controller\Adminhtml\Chat;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * The chat page: a conversation (id), or a new one.
 */
class Index extends AbstractChat implements HttpGetActionInterface
{
    public function execute()
    {
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Zero_AiAssistant::chat');
        $page->getConfig()->getTitle()->prepend(__('AI Assistant'));
        $block = $page->getLayout()->getBlock('zero.ai.chat');
        if ($block) {
            $id = (int) $this->getRequest()->getParam('id');
            $block->setData('conversation_id', $this->chat->conversation($id) ? $id : 0);
        }

        return $page;
    }
}
