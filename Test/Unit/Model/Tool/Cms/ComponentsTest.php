<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Tool\Cms;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Tool\Cms\Components;
use Zero\AiAssistant\Model\Tool\Cms\Content;
use Zero\AiAssistant\Model\Uploads;
use Zero\Cms\Model\Component\Registry;
use Zero\Cms\Model\Content\Repository;

class ComponentsTest extends TestCase
{
    private function components(): Components
    {
        $registry = $this->createStub(Registry::class);
        $registry->method('get')->willReturnCallback(static fn (string $type) => in_array($type, ['hero', 'tiles'], true) ? ['fields' => []] : null);

        return new Components(
            $this->createStub(Content::class),
            $registry,
            $this->createStub(Repository::class),
            $this->createStub(Uploads::class),
            $this->createStub(ManagerInterface::class),
            $this->createStub(CacheInterface::class)
        );
    }

    public function testFindsUploadedPhotosInImageFieldsAtAnyDepth(): void
    {
        $components = [
            ['type' => 'hero', 'data' => ['image' => ['upload' => 4, 'alt' => 'Shop']]],
            ['type' => 'tiles', 'data' => ['items' => [['image' => ['upload' => '7']], ['image' => ['path' => 'a.jpg', 'alt' => '']], ['image' => ['upload' => 4]]]]],
        ];
        $this->assertSame([4, 7], $this->components()->uploadIds($components));
    }

    public function testNamesTheTypesNoModuleDefines(): void
    {
        $this->assertSame(['nope', '(no type)'], $this->components()->unknownTypes([
            ['type' => 'hero'], ['type' => 'nope'], ['data' => []], ['type' => 'nope'],
        ]));
    }
}
