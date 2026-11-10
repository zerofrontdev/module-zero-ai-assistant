<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Block\Template;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Zero\AiAssistant\ViewModel\Chat;

/**
 * The chat's actions: the admin user needs Zero_AiAssistant::chat; what the
 * assistant then does for them, their role's other resources.
 */
abstract class AbstractChat extends Action
{
    public const ADMIN_RESOURCE = 'Zero_AiAssistant::chat';

    public function __construct(
        Context $context,
        protected readonly Chat $chat
    ) {
        parent::__construct($context);
    }

    /**
     * With Chat in the Admin off (Zero > AI Assistant) the chat's actions
     * are out of reach for everyone; the Claude connector is unaffected.
     */
    protected function _isAllowed()
    {
        return $this->chat->isAdminChatOn() && parent::_isAllowed();
    }

    /**
     * A partial of the chat page (HTMX swaps it in).
     */
    protected function partial(string $template, array $data = []): Raw
    {
        $html = $this->_view->getLayout()->createBlock(Template::class)
            ->setTemplate('Zero_AiAssistant::' . $template)
            ->addData(['view_model' => $this->chat] + $data)
            ->toHtml();
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);

        return $result->setHeader('Content-Type', 'text/html; charset=UTF-8')->setContents($html);
    }
}
