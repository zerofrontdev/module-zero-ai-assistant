<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
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

    public function testPoolToolsFollowTheirModuleAndTheRole(): void
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(static fn (string $r) => $r !== 'Zero_Reviews::denied');
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturnCallback(static fn (string $m) => $m === 'Zero_Blog');
        $tools = new Tools($authorization, $modules, [
            'search_products' => $this->tool('search_products', 'Magento_Catalog::products'),
            'search_posts' => $this->tool('search_posts', 'Zero_Blog::posts'),
            'secret' => $this->tool('secret', 'Zero_Blog::denied_by_role'),
            'list_reviews' => $this->tool('list_reviews', 'Zero_Reviews::reviews'),
        ], [
            'search_posts' => 'Zero_Blog',
            'list_reviews' => 'Zero_Reviews',
        ]);
        $this->assertSame(['search_products', 'search_posts', 'secret'], array_keys($tools->allowed()));
        $this->assertSame([], $tools->changingNames());
    }

    public function testAToolOfADisabledModuleIsNotTouched(): void
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(false);
        // No method may be called on it (a \Proxy would not even be built).
        $off = $this->createMock(ToolInterface::class);
        $off->expects($this->never())->method($this->anything());
        $tools = new Tools($authorization, $modules, ['list_returns' => $off], ['list_returns' => 'Zero_Returns']);
        $this->assertSame([], $tools->allowed());
        $this->assertSame([], $tools->definitions());
        $this->assertNull($tools->get('list_returns'));
    }

    public function testAnEmptyOrUnknownPoolEntryIsIgnored(): void
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(true);
        $tools = new Tools(
            $authorization,
            $modules,
            ['empty' => null, 'text' => 'Zero\\Nothing', 'list' => [], 'ok' => $this->tool('ok', 'Magento_Catalog::products')],
            ['ok' => '', 'ghost' => 'Zero_Ghost', 'empty' => 'Zero_Blog']
        );
        $this->assertSame(['ok'], array_keys($tools->allowed()));
    }

    public function testNoRequiresMeansTheToolIsAlwaysThere(): void
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);
        $modules = $this->createMock(ModuleManager::class);
        $modules->expects($this->never())->method('isEnabled');
        $tools = new Tools($authorization, $modules, ['a' => $this->tool('a', 'r'), 'b' => $this->tool('b', 'r', 'catalog', true)]);
        $this->assertSame(['a', 'b'], array_keys($tools->allowed()));
        $this->assertSame(['b'], $tools->changingNames());
    }

    public function testARoleLimitedToAreasGetsOnlyThose(): void
    {
        $granted = ['Magento_Catalog::products', 'Magento_Sales::sales_order', 'Magento_Sales::ship', 'Zero_AiAssistant::area_sales'];
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(function (string $r) use (&$granted) {
            return in_array($r, $granted, true);
        });
        $modules = $this->createStub(ModuleManager::class);
        $tools = new Tools($authorization, $modules, [
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
        $commands = new Commands($authorization, $modules, [
            'reviews' => ['label' => 'Reviews', 'text' => 'Show reviews.', 'sort_order' => 70],
            'post' => ['label' => 'Write a post', 'text' => 'Write a post about {args}.', 'sort_order' => 10],
        ], ['post' => 'Zero_Blog', 'reviews' => 'Zero_Reviews']);
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
        return new Tools($authorization, $this->createStub(ModuleManager::class));
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
        $tools = new Tools($authorization, $this->createStub(ModuleManager::class));

        $tools->authorize($this->recordsTool('Magento_Review::reviews_all'), ['review_ids' => [1, 2]]);
    }

    public function testAToolWithoutARecordAclIsNotCheckedAgain(): void
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects($this->never())->method('isAllowed');
        $tools = new Tools($authorization, $this->createStub(ModuleManager::class));

        $tools->authorize($this->tool('search_products', 'Magento_Catalog::products'), ['review_ids' => [1, 2]]);
    }
}
