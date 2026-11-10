<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Acl;

use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Authorization\Policy\Acl as AclPolicy;
use Magento\Framework\AuthorizationInterface;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;

/**
 * The admin ACL for one admin user, outside an admin session: what a Claude
 * connection (Zero_Mcp) or a bulk job running in the queue may still do. The
 * assistant's own Tools and Commands take it in place of the session's
 * authorization. An inactive or removed user is allowed nothing.
 */
class UserAuthorization implements AuthorizationInterface
{
    private ?int $roleId = null;

    public function __construct(
        private readonly AclPolicy $policy,
        private readonly RoleCollectionFactory $roleCollectionFactory,
        private readonly UserCollectionFactory $userCollectionFactory,
        private readonly int $userId
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isAllowed($resource, $privilege = null)
    {
        $roleId = $this->roleId();
        if ($roleId === null) {
            return false;
        }
        try {
            return (bool) $this->policy->isAllowed((string) $roleId, (string) $resource, $privilege);
        } catch (\Throwable) {
            return false;
        }
    }

    public function userId(): int
    {
        return $this->userId;
    }

    /**
     * The user's role, when the user exists and is active.
     */
    public function roleId(): ?int
    {
        if ($this->roleId !== null) {
            return $this->roleId ?: null;
        }
        $user = $this->userCollectionFactory->create()->addFieldToFilter('user_id', $this->userId)->getFirstItem();
        if (!$user->getId() || !(int) $user->getIsActive()) {
            $this->roleId = 0;
            return null;
        }
        $role = $this->roleCollectionFactory->create()->setUserFilter($this->userId, UserContextInterface::USER_TYPE_ADMIN)->getFirstItem();
        $this->roleId = (int) $role->getId();
        return $this->roleId ?: null;
    }
}
