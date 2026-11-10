<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;

/**
 * Commands: "/describe 24-UG06, 24-MB01" in the message field stands for a
 * whole request (the command's text, with what follows it in place of
 * {args}). The admin sees the request as sent. Each command shows only to
 * a role with its resource; the tools behind it check their own anyway.
 * More come from di.xml (Model\Commands's commands).
 */
class Commands
{
    /**
     * @param array<string, array{label: string, text: string, resource?: string, example?: string, area?: string, changes?: bool}> $commands
     */
    /**
     * @param array<string, array<string, array>> $modules Module name => its commands, used when it is enabled
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly ModuleManager $moduleManager,
        private readonly array $commands = [],
        private readonly array $modules = []
    ) {
    }

    /**
     * @return array<string, array{label: string, text: string, example: string}> name => command, for this role
     */
    public function allowed(): array
    {
        $allowed = [];
        $limited = false;
        foreach (Tools::AREAS as $area) {
            $limited = $limited || $this->authorization->isAllowed('Zero_AiAssistant::area_' . $area);
        }
        foreach ($this->all() as $name => $command) {
            if (!empty($command['resource']) && !$this->authorization->isAllowed($command['resource'])) {
                continue;
            }
            // A role limited to areas sees only the commands of its areas (and "change" ones only with "change").
            if ($limited && !empty($command['area']) && (!$this->authorization->isAllowed('Zero_AiAssistant::area_' . $command['area'])
                || (!empty($command['changes']) && !$this->authorization->isAllowed('Zero_AiAssistant::area_' . $command['area'] . '_change')))) {
                continue;
            }
            $allowed[$name] = ['label' => (string) __($command['label']), 'text' => $command['text'], 'example' => (string) ($command['example'] ?? '')];
        }
        return $allowed;
    }

    /**
     * The message with its command, if it starts with one this role has,
     * spelled out; otherwise as it is.
     */
    public function expand(string $message): string
    {
        if (!preg_match('#^/([a-z][a-z0-9-]*)(?:\s+(.*))?$#is', trim($message), $m)) {
            return $message;
        }
        $command = $this->allowed()[strtolower($m[1])] ?? null;
        if ($command === null) {
            return $message;
        }
        return self::fill($command['text'], trim($m[2] ?? ''));
    }

    /**
     * {args} replaced; [ … ] parts kept only when there are args.
     */
    public static function fill(string $text, string $args): string
    {
        $text = (string) preg_replace_callback('/\[([^\]]*)\]/', static fn ($m) => $args !== '' ? $m[1] : '', $text);
        return trim((string) preg_replace('/\s+/', ' ', str_replace('{args}', $args, $text)));
    }

    /**
     * @return array<string, array>
     */
    private function all(): array
    {
        $modules = [];
        foreach ($this->modules as $module => $commands) {
            if ($this->moduleManager->isEnabled((string) $module)) {
                $modules += (array) $commands;
            }
        }

        return $this->commands + [
            'describe' => [
                'area' => 'catalog',
                'changes' => true,
                'label' => 'Write descriptions and short descriptions',
                'text' => 'Write a new description and short description for these products: {args}. First show me samples for two of them; when I agree, write them all.',
                'resource' => 'Magento_Catalog::products',
                'example' => '24-UG06, 24-MB01',
            ],
            'short' => [
                'area' => 'catalog',
                'changes' => true,
                'label' => 'Write short descriptions',
                'text' => 'Write a new short description for these products: {args}. First show me samples for two of them; when I agree, write them all.',
                'resource' => 'Magento_Catalog::products',
                'example' => 'category Bags',
            ],
            'seo' => [
                'area' => 'catalog',
                'changes' => true,
                'label' => 'Write meta titles and descriptions',
                'text' => 'Write a meta title and a meta description for these products: {args}. Show me samples for two first.',
                'resource' => 'Magento_Catalog::products',
                'example' => 'all products in Gear',
            ],
            'empty' => [
                'area' => 'catalog',
                'changes' => true,
                'label' => 'Fill in products without texts',
                'text' => 'Find the products[ in {args}] with an empty description or short description and write them. Show me how many and samples for two first.',
                'resource' => 'Magento_Catalog::products',
                'example' => 'Women',
            ],
            'product' => [
                'area' => 'catalog',
                'changes' => true,
                'label' => 'Create a product from photos',
                'text' => 'Create a product from the attached photos[: {args}].',
                'resource' => 'Magento_Catalog::products',
                'example' => 'price 49, size S–XL',
            ],
            'orders' => [
                'area' => 'sales',
                'changes' => false,
                'label' => 'Show orders',
                'text' => 'Show the orders[ of {args}].',
                'resource' => 'Magento_Sales::sales_order',
                'example' => 'today',
            ],
            'setup' => [
                'area' => 'system',
                'changes' => true,
                'label' => 'Check the store\'s basic settings',
                'text' => 'Check the store\'s basic settings with get_settings: list what is missing or looks wrong, ask me for the missing values in one go, then propose set_settings with my answers[. {args}].',
                'resource' => 'Magento_Config::config',
                'example' => 'phone +380 67 123 45 67',
            ],
            'status' => [
                'area' => 'system',
                'changes' => false,
                'label' => 'Store status: caches, indexes, cron',
                'text' => 'Show the store\'s status with store_status and say what needs attention[: {args}].',
                'resource' => 'Magento_Backend::cache',
                'example' => '',
            ],
        ] + $modules;
    }
}
