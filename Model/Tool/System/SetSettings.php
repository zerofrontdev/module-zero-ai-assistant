<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\System;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;
use Zero\AiAssistant\Model\Acl\UserAuthorizationFactory;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;

/**
 * set_settings: the basic settings changed in the default scope, the
 * config and page caches cleaned so the store shows them at once.
 */
class SetSettings implements ToolInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $writer,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly TypeListInterface $cacheTypes,
        private readonly UserAuthorizationFactory $authorizationFactory
    ) {
    }

    public function name(): string
    {
        return 'set_settings';
    }

    public function description(): string
    {
        return 'Change basic store settings (the paths get_settings lists; nothing else can be set): values is an object of path => value, for all store views. An empty string clears one. Shown to the admin to confirm first; the caches are cleaned after.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'values' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'boolean', 'number']], 'description' => 'path => new value'],
        ], 'required' => ['values']];
    }

    public function resource(): string
    {
        return 'Magento_Config::config';
    }

    public function area(): string
    {
        return 'system';
    }

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        $lines = [];
        foreach ($this->values($input, $context) as $path => $value) {
            [$label, $kind, $group] = Settings::PATHS[$path];
            $old = trim((string) $this->scopeConfig->getValue($path));
            $lines[$group . ': ' . $label] = ($old === '' ? '(empty)' : $old) . ' → ' . ($value === '' ? '(empty)' : $value);
        }

        return ['title' => (string) __('Change %1 store setting(s)', count($lines)), 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        $values = $this->values($input, $context);
        foreach ($values as $path => $value) {
            if ($value === '') {
                $this->writer->delete($path);
            } else {
                $this->writer->save($path, $value);
            }
        }
        $this->reinitableConfig->reinit();
        $this->cacheTypes->cleanType('config');
        $this->cacheTypes->cleanType('full_page');

        return ['saved' => $values];
    }

    /**
     * @return array<string, string>
     * @throws LocalizedException
     */
    private function values(array $input, Context $context): array
    {
        $authorization = $this->authorizationFactory->create(['userId' => $context->userId]);
        $values = (array) ($input['values'] ?? []);
        if (!$values) {
            throw new LocalizedException(__('No settings given.'));
        }
        $clean = [];
        foreach ($values as $path => $value) {
            $clean[(string) $path] = Settings::clean((string) $path, $value);
            if (!Settings::mayUse((string) $path, $authorization)) {
                throw new LocalizedException(__('Your role may not change the %1 settings (Stores > Configuration).', strtok((string) $path, '/')));
            }
        }

        return $clean;
    }
}
