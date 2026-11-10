<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\System;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Zero\AiAssistant\Model\Acl\UserAuthorizationFactory;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;

/**
 * get_settings: the basic store settings (Settings::PATHS), default scope,
 * with the empty required ones marked.
 */
class GetSettings extends ReadTool
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UserAuthorizationFactory $authorizationFactory
    ) {
    }

    public function name(): string
    {
        return 'get_settings';
    }

    public function description(): string
    {
        return 'The store\'s basic settings (store information, locale, store email addresses, contact, shipping origin, SEO defaults): path, label, value, and "missing" on a required one that is empty. For a setup check: list the missing ones, ask the admin for them in one go, then set_settings. Read only.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function resource(): string
    {
        return 'Magento_Config::config';
    }

    public function area(): string
    {
        return 'system';
    }

    public function run(array $input, Context $context): array
    {
        $groups = [];
        $missing = [];
        $authorization = $this->authorizationFactory->create(['userId' => $context->userId]);
        foreach (Settings::PATHS as $path => [$label, $kind, $group]) {
            // Only the sections the role may open in Stores > Configuration.
            if (!Settings::mayUse($path, $authorization)) {
                continue;
            }
            $value = $this->scopeConfig->getValue($path);
            $value = $kind === 'bool' ? (bool) $value : trim((string) $value);
            $row = ['path' => $path, 'label' => $label, 'value' => $value];
            if ($value === '' && in_array($path, Settings::REQUIRED, true)) {
                $row['missing'] = true;
                $missing[] = $path;
            }
            $groups[$group][] = $row;
        }

        return ['settings' => $groups, 'missing' => $missing];
    }
}
