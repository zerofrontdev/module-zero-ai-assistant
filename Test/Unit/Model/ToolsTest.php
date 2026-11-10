<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Commands;
use Magento\Framework\Exception\LocalizedException;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\InputAclInterface;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\AiAssistant\Model\Tool\ReadTool;
use Zero\AiAssistant\Model\Tools;

/**
 * Tools and commands of other modules come in only when that module is
 * enabled, and only for a role with their resource.
 */
class ToolsTest extends TestCase
{
    private function tool(string $name, string $resource, string $area = 'catalog', bool $changes = false): ReadTool
    {
        return new class ($name, $resource, $area, $changes) extends ReadTool {
            public function __construct(private readonly string $n, private readonly string $r, private readonly string $a, private readonly bool $w)
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
                return ['type' => 'object'];
            }
            public function resource(): string
            {
                return $this->r;
            }
            public function area(): string
            {
                return $this->a;
            }
            public function changes(): bool
            {
                return $this->w;
            }
            public function run(array $input, Context $context): array
            {
                return [];
            }
        };
    }

    public function testModuleToolsFollowTheModuleAndTheRole(): void
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(static fn (string $r) => $r !== 'Zero_Reviews::denied');
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturnCallback(static fn (string $m) => $m === 'Zero_Blog');
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn (string $class) => match ($class) {
            \ArrayObject::class => $this->tool('search_posts', 'Zero_Blog::posts'),
            \ArrayIterator::class => $this->tool('secret', 'Zero_Reviews::denied'),
            default => $this->fail('a disabled module\'s tool was made: ' . $class),
        });
        $tools = new Tools($authorization, $modules, $objectManager, [$this->tool('search_products', 'Magento_Catalog::products')], [
            'Zero_Blog' => [\ArrayObject::class, \ArrayIterator::class],
            'Zero_Reviews' => ['ReviewsTool'],
        ]);
        $this->assertSame(['search_products', 'search_posts'], array_keys($tools->allowed()));
    }

    public function testAToolClassThePackageDoesNotShipIsLeftOut(): void
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(true);
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn (string $class) => match ($class) {
            \ArrayObject::class => $this->tool('search_posts', 'Zero_Blog::posts'),
            default => $this->fail('a missing class was made: ' . $class),
        });
        $tools = new Tools($authorization, $modules, $objectManager, [], [
            'Zero_NovaPoshta' => ['Zero\\AiAssistant\\Model\\Tool\\NovaPoshta\\NotShipped'],
            'Zero_Blog' => [\ArrayObject::class],
        ]);
        $this->assertSame(['search_posts'], array_keys($tools->allowed()));
    }

    public function testARoleLimitedToAreasGetsOnlyThose(): void
    {
        $granted = ['Magento_Catalog::products', 'Magento_Sales::sales_order', 'Magento_Sales::ship', 'Zero_AiAssistant::area_sales'];
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(function (string $r) use (&$granted) {
            return in_array($r, $granted, true);
        });
        $modules = $this->createStub(ModuleManager::class);
        $tools = new Tools($authorization, $modules, $this->createStub(ObjectManagerInterface::class), [
            $this->tool('search_products', 'Magento_Catalog::products', 'catalog'),
            $this->tool('list_orders', 'Magento_Sales::sales_order', 'sales'),
            $this->tool('ship_order', 'Magento_Sales::ship', 'sales', true),
        ]);
        $this->assertTrue($tools->isLimited());
        $this->assertSame(['list_orders'], array_keys($tools->allowed()), 'orders to look up only: no catalog, no shipping without "change"');

        $granted[] = 'Zero_AiAssistant::area_sales_change';
        $this->assertSame(['list_orders', 'ship_order'], array_keys($tools->allowed()));

        $granted = ['Magento_Catalog::products', 'Magento_Sales::sales_order', 'Magento_Sales::ship'];
        $this->assertFalse($tools->isLimited());
        $this->assertSame(['search_products', 'list_orders', 'ship_order'], array_keys($tools->allowed()), 'no area ticked: everything the admin resources allow');
    }

    public function testModuleCommandsFollowTheModule(): void
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturnCallback(static fn (string $m) => $m === 'Zero_Blog');
        $commands = new Commands($authorization, $modules, [], [
            'Zero_Blog' => ['post' => ['label' => 'Write a post', 'text' => 'Write a post about {args}.']],
            'Zero_Reviews' => ['reviews' => ['label' => 'Reviews', 'text' => 'Show reviews.']],
        ]);
        $allowed = $commands->allowed();
        $this->assertArrayHasKey('post', $allowed);
        $this->assertArrayHasKey('orders', $allowed, 'the built-in ones stay');
        $this->assertArrayNotHasKey('reviews', $allowed);
        $this->assertSame('Write a post about tea.', $commands->expand('/post tea'));
    }

    private function recordsTool(string $needs): ToolInterface
    {
        $tool = $this->createStubForIntersectionOfInterfaces([ToolInterface::class, InputAclInterface::class]);
        $tool->method('name')->willReturn('moderate_reviews');
        $tool->method('resourceFor')->willReturnCallback(static fn (array $input) => $input === ['review_ids' => [1, 2]] ? $needs : 'unexpected input');
        return $tool;
    }

    private function toolsFor(array $granted): Tools
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(static fn (string $r) => in_array($r, $granted, true));
        return new Tools($authorization, $this->createStub(ModuleManager::class), $this->createStub(ObjectManagerInterface::class));
    }

    public function testARecordAclTheRoleLacksIsRefused(): void
    {
        $tools = $this->toolsFor(['Magento_Review::pending']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Your role doesn\'t allow moderate_reviews on these records: it needs Magento_Review::reviews_all.');
        $tools->authorize($this->recordsTool('Magento_Review::reviews_all'), ['review_ids' => [1, 2]]);
    }

    public function testARecordAclTheRoleHasPasses(): void
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects($this->once())->method('isAllowed')->with('Magento_Review::reviews_all')->willReturn(true);
        $tools = new Tools($authorization, $this->createStub(ModuleManager::class), $this->createStub(ObjectManagerInterface::class));

        $tools->authorize($this->recordsTool('Magento_Review::reviews_all'), ['review_ids' => [1, 2]]);
    }

    public function testAToolWithoutARecordAclIsNotCheckedAgain(): void
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects($this->never())->method('isAllowed');
        $tools = new Tools($authorization, $this->createStub(ModuleManager::class), $this->createStub(ObjectManagerInterface::class));

        $tools->authorize($this->tool('search_products', 'Magento_Catalog::products'), ['review_ids' => [1, 2]]);
    }
}
