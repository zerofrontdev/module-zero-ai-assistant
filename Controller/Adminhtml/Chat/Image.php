<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Uploads;
use Zero\AiAssistant\ViewModel\Chat;

/**
 * An image uploaded into one of the admin user's conversations.
 */
class Image extends AbstractChat implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        Chat $chat,
        private readonly Conversations $conversations,
        private readonly Uploads $uploads
    ) {
        parent::__construct($context, $chat);
    }

    public function execute()
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $upload = $this->conversations->upload((int) $this->getRequest()->getParam('id'), $this->chat->userId());
        $path = $upload ? $this->uploads->absolutePath($upload) : '';
        if (!$path || !is_file($path)) {
            return $result->setHttpResponseCode(404)->setContents('');
        }

        return $result->setHeader('Content-Type', (string) $upload['mime'])
            ->setHeader('Cache-Control', 'private, max-age=86400')
            ->setContents((string) file_get_contents($path));
    }
}
