<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Tool\ToolInterface;

/**
 * Guards for the tool pool that Zero_AiAssistant and its bridge modules (Zero_<Module>AiAssistant)
 * fill through di.xml. They read the real etc/di.xml and etc/module.xml of every module in
 * app/code/Zero, so a new bridge is checked without touching this test.
 */
class ToolPoolTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../';

    /** @var array<string, array{class: string, sortOrder: int, file: string}>|null */
    private ?array $tools = null;

    protected function setUp(): void
    {
        if (!is_dir(self::MODULES . 'AiAssistant') || !glob(self::MODULES . '*/etc/di.xml')) {
            self::markTestSkipped('app/code/Zero is not here (the module is installed as a package).');
        }
    }

    public function testEveryRequiresKeyIsAPoolItem(): void
    {
        $requires = $this->items('requires');
        $this->assertNotSame([], $requires);
        $this->assertSame([], array_values(array_diff(array_keys($requires), array_keys($this->tools()))), 'a `requires` key that is no tool');
    }

    public function testNoSortOrderIsUsedTwiceAcrossAllModules(): void
    {
        $seen = [];
        $twice = [];
        foreach ($this->tools() as $name => $tool) {
            if (isset($seen[$tool['sortOrder']])) {
                $twice[] = $seen[$tool['sortOrder']] . ' and ' . $name . ' (' . $tool['sortOrder'] . ')';
            }
            $seen[$tool['sortOrder']] = $name;
        }
        $this->assertSame([], $twice);
        $this->assertNotContains(0, array_column($this->tools(), 'sortOrder'), 'every item needs a sortOrder');
    }

    public function testEveryToolClassExistsAndIsATool(): void
    {
        $virtual = $this->virtualTypes();
        $bad = [];
        foreach ($this->tools() as $name => $tool) {
            $class = preg_replace('/\\\\Proxy$/', '', $tool['class']);
            while (isset($virtual[$class])) {
                $class = $virtual[$class];
            }
            if (!class_exists($class)) {
                $bad[] = $name . ': ' . $class . ' does not exist (' . $tool['file'] . ')';
            } elseif (!is_subclass_of($class, ToolInterface::class)) {
                $bad[] = $name . ': ' . $class . ' is not a ToolInterface';
            }
        }
        $this->assertSame([], $bad);
    }

    public function testEveryBridgeSequencesAiAssistantAndItsModule(): void
    {
        $bridges = array_diff(glob(self::MODULES . '*AiAssistant/etc/module.xml') ?: [], [self::MODULES . 'AiAssistant/etc/module.xml']);
        $this->assertGreaterThanOrEqual(9, count($bridges));
        $bad = [];
        foreach ($bridges as $file) {
            $module = simplexml_load_file($file)->module;
            $name = (string) $module['name'];
            $sequence = [];
            foreach ($module->sequence->module ?? [] as $dependency) {
                $sequence[] = (string) $dependency['name'];
            }
            $foreign = substr($name, 0, -strlen('AiAssistant'));
            $this->assertNotSame('Zero_', $foreign);
            foreach (['Zero_AiAssistant', $foreign] as $needed) {
                if (!in_array($needed, $sequence, true)) {
                    $bad[] = $name . ' does not sequence ' . $needed;
                }
            }
        }
        $this->assertSame([], $bad);
    }

    public function testABridgeOnlyRequiresItsOwnModule(): void
    {
        $bad = [];
        foreach (glob(self::MODULES . '*AiAssistant/etc/di.xml') ?: [] as $file) {
            $module = basename(dirname($file, 2));
            if ($module === 'AiAssistant') {
                continue;
            }
            $own = 'Zero_' . substr($module, 0, -strlen('AiAssistant'));
            $xml = simplexml_load_file($file);
            foreach ($xml->type as $type) {
                foreach ($type->arguments->argument as $argument) {
                    if ((string) $argument['name'] !== 'requires') {
                        continue;
                    }
                    foreach ($argument->item as $item) {
                        if (trim((string) $item) !== $own) {
                            $bad[] = $module . ': ' . $item['name'] . ' requires ' . trim((string) $item);
                        }
                    }
                }
            }
        }
        $this->assertSame([], $bad);
    }

    public function testAiAssistantNamesNoClassOfTheModulesItsBridgesServe(): void
    {
        $dir = self::MODULES . 'AiAssistant';
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/Test/') || !preg_match('/\.(php|xml|phtml)$/', $path)) {
                continue;
            }
            if (preg_match('/Zero\\\\{1,2}(Blog|Banner|QuoteRequest|Returns|Reviews|Staging|NovaPoshta|Ukrposhta|Checkbox)\\\\/', (string) file_get_contents($path), $m)) {
                $found[] = substr($path, strlen($dir) + 1) . ' names Zero\\' . $m[1];
            }
        }
        $this->assertSame([], $found, 'the tool belongs in the bridge module of that module');
    }

    public function testNoToolClassOfAiAssistantOrABridgeTakesTheObjectManager(): void
    {
        $found = [];
        foreach (array_merge(glob(self::MODULES . '*AiAssistant/Model') ?: [], glob(self::MODULES . 'AiAssistant/Model') ?: []) as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (str_ends_with($file->getPathname(), '.php') && str_contains((string) file_get_contents($file->getPathname()), 'ObjectManager')) {
                    $found[] = $file->getPathname();
                }
            }
        }
        $this->assertSame([], $found);
    }

    /**
     * @return array<string, array{class: string, sortOrder: int, file: string}> the `tools` pool of every module, sorted by sortOrder
     */
    private function tools(): array
    {
        if ($this->tools === null) {
            $tools = [];
            foreach (glob(self::MODULES . '*/etc/di.xml') as $file) {
                foreach ($this->poolItems($file, 'tools') as $item) {
                    $tools[(string) $item['name']] = [
                        'class' => trim((string) $item),
                        'sortOrder' => (int) $item['sortOrder'],
                        'file' => basename(dirname($file, 2)),
                    ];
                }
            }
            uasort($tools, static fn (array $a, array $b) => $a['sortOrder'] <=> $b['sortOrder']);
            $this->tools = $tools;
        }
        return $this->tools;
    }

    /**
     * @return array<string, string> item name => value of a string argument of Tools, over every module
     */
    private function items(string $argument): array
    {
        $map = [];
        foreach (glob(self::MODULES . '*/etc/di.xml') as $file) {
            foreach ($this->poolItems($file, $argument) as $item) {
                $map[(string) $item['name']] = trim((string) $item);
            }
        }
        return $map;
    }

    /**
     * @return \SimpleXMLElement[]
     */
    private function poolItems(string $file, string $argument): array
    {
        $found = [];
        foreach (simplexml_load_file($file)->type as $type) {
            if ((string) $type['name'] !== 'Zero\\AiAssistant\\Model\\Tools') {
                continue;
            }
            foreach ($type->arguments->argument as $arg) {
                if ((string) $arg['name'] === $argument) {
                    foreach ($arg->item as $item) {
                        $found[] = $item;
                    }
                }
            }
        }
        return $found;
    }

    /**
     * @return array<string, string> virtual type => the type it extends, from every di.xml
     */
    private function virtualTypes(): array
    {
        $types = [];
        foreach (glob(self::MODULES . '*/etc/di.xml') as $file) {
            foreach (simplexml_load_file($file)->virtualType as $virtual) {
                $types[(string) $virtual['name']] = (string) $virtual['type'];
            }
        }
        return $types;
    }
}
