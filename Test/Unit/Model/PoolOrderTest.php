<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Commands;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\AiAssistant\Model\Tools;

/**
 * The tool and command lists the real etc/di.xml files of AiAssistant and of its bridge modules give (items sorted by sortOrder / sort_order,
 * the way Magento's array interpreter does) equal the lists the code before the pool refactoring
 * gave (_files/pool-order-before-refactor.json, generated from that code): the same names in the
 * same order for the same enabled modules. The e2e test and Claude see this order.
 */
class PoolOrderTest extends TestCase
{
    /** AiAssistant's own di.xml and the di.xml of every bridge module (Zero_<Module>AiAssistant) next to it. */
    private const DI = __DIR__ . '/../../../etc/di.xml';
    private const BRIDGES = __DIR__ . '/../../../../*AiAssistant/etc/di.xml';

    /**
     * @return array<string, array{0: ?string[], 1: string}>
     */
    public static function enabledSets(): array
    {
        return [
            'every module on' => [null, 'all'],
            'blog, cms, checkbox' => [['Zero_Blog', 'Zero_Cms', 'Zero_Checkbox'], 'blog_cms_checkbox'],
            'no optional module (not even Zero_Cms)' => [[], 'none'],
        ];
    }

    #[DataProvider('enabledSets')]
    public function testToolsKeepTodaysOrder(?array $enabled, string $set): void
    {
        $pool = $this->pool('Tools', 'tools');
        $tools = new Tools($this->everything(), $this->modules($enabled), $this->stubTools($pool), $this->stringMap('Tools'));

        $this->assertSame($this->snapshot()[$set]['tools'], array_keys($tools->allowed()));
    }

    #[DataProvider('enabledSets')]
    public function testCommandsKeepTodaysOrder(?array $enabled, string $set): void
    {
        $commands = new Commands($this->everything(), $this->modules($enabled), $this->commandPool(), $this->stringMap('Commands'));

        $this->assertSame($this->snapshot()[$set]['commands'], array_keys($commands->allowed()));
    }

    public function testEveryRequiresKeyIsAPoolItemAndNoSortOrderRepeats(): void
    {
        $pool = $this->pool('Tools', 'tools');
        $requires = $this->stringMap('Tools');
        $this->assertSame([], array_values(array_diff(array_keys($requires), array_keys($pool))), 'a `requires` key that is no tool');
        $orders = array_column($pool, 'sortOrder');
        $this->assertSame($orders, array_values(array_unique($orders)), 'a sortOrder is used twice');
        $this->assertNotContains(0, $orders, 'every item has a sortOrder');
        $this->assertCount(55, $pool);
        $this->assertCount(29, $requires);
        $this->assertSame([], array_values(array_diff(array_keys($this->stringMap('Commands')), array_keys($this->commandPool()))));
    }

    public function testCommandsWithASortOrderComeAfterTheBuiltInOnesInThatOrder(): void
    {
        $commands = new Commands($this->everything(), $this->modules(null), [
            'last' => ['label' => 'L', 'text' => 'l', 'sort_order' => 90],
            'first' => ['label' => 'F', 'text' => 'f', 'sort_order' => 5],
            'ahead' => ['label' => 'A', 'text' => 'a'],
        ]);
        $names = array_keys($commands->allowed());
        $this->assertSame('ahead', $names[0], 'without a sort_order: ahead of the built-in ones, as before');
        $this->assertSame(['first', 'last'], array_slice($names, -2));
        $this->assertContains('describe', $names);
    }

    private function snapshot(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/_files/pool-order-before-refactor.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function everything(): AuthorizationInterface
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);
        return $authorization;
    }

    private function modules(?array $enabled): ModuleManager
    {
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturnCallback(static fn (string $m) => $enabled === null || in_array($m, $enabled, true));
        return $modules;
    }

    /**
     * @return array<string, array{class: string, sortOrder: int}> the items of one di.xml argument, sorted by sortOrder
     */
    private function pool(string $type, string $argument): array
    {
        $items = [];
        foreach ($this->arguments($type, $argument) as $item) {
            $items[(string) $item['name']] = ['class' => trim((string) $item), 'sortOrder' => (int) $item['sortOrder']];
        }
        uasort($items, static fn (array $a, array $b) => $a['sortOrder'] <=> $b['sortOrder']);
        return $items;
    }

    /**
     * @return \SimpleXMLElement[] what every `<type>` of that class in any module's di.xml declares for the argument
     */
    private function arguments(string $type, string $argument): array
    {
        $found = [];
        $files = array_unique(array_merge([self::DI], glob(self::BRIDGES) ?: []));
        foreach ($files as $file) {
            $xml = simplexml_load_file($file);
            foreach ($xml->type as $node) {
                if ((string) $node['name'] !== 'Zero\\AiAssistant\\Model\\' . $type) {
                    continue;
                }
                foreach ($node->arguments->argument as $arg) {
                    if ((string) $arg['name'] === $argument) {
                        foreach ($arg->item as $item) {
                            $found[] = $item;
                        }
                    }
                }
            }
        }
        return $found;
    }

    /**
     * @return array<string, string> the `requires` map
     */
    private function stringMap(string $type): array
    {
        $map = [];
        foreach ($this->arguments($type, 'requires') as $item) {
            $map[(string) $item['name']] = trim((string) $item);
        }
        return $map;
    }

    /**
     * @param array<string, array{class: string, sortOrder: int}> $pool
     * @return ToolInterface[]
     */
    private function stubTools(array $pool): array
    {
        $tools = [];
        foreach (array_keys($pool) as $name) {
            $tools[$name] = new class ($name) extends ReadTool {
                public function __construct(private readonly string $n)
                {
                }
                public function name(): string
                {
                    return $this->n;
                }
                public function description(): string
                {
                    return $this->n;
                }
                public function schema(): array
                {
                    return [];
                }
                public function resource(): string
                {
                    return 'any';
                }
                public function area(): string
                {
                    return 'catalog';
                }
                public function changes(): bool
                {
                    return false;
                }
                public function run(array $input, Context $context): array
                {
                    return [];
                }
            };
        }
        return $tools;
    }

    /**
     * @return array<string, array<string, mixed>> the `commands` items, sort_order included
     */
    private function commandPool(): array
    {
        $commands = [];
        foreach ($this->arguments('Commands', 'commands') as $item) {
            $command = [];
            foreach ($item->item as $field) {
                $command[(string) $field['name']] = trim((string) $field);
            }
            $commands[(string) $item['name']] = $command + ['label' => 'x', 'text' => 'x'];
        }
        return $commands;
    }
}
