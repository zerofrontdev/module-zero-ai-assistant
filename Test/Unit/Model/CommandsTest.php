<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Commands;

/**
 * "/describe 24-UG06" spelled out, for the roles that have the command.
 */
class CommandsTest extends TestCase
{
    public function testACommandIsSpelledOutWithItsArguments(): void
    {
        $commands = new Commands($this->role(['Magento_Catalog::products']), $this->createStub(\Magento\Framework\Module\Manager::class));
        $this->assertSame(
            'Write a new description and short description for these products: 24-UG06, 24-MB01. First show me samples for two of them; when I agree, write them all.',
            $commands->expand('/describe 24-UG06, 24-MB01')
        );
        $this->assertSame(
            'Find the products in Women with an empty description or short description and write them. Show me how many and samples for two first.',
            $commands->expand('/empty Women')
        );
        $this->assertSame(
            'Find the products with an empty description or short description and write them. Show me how many and samples for two first.',
            $commands->expand('/EMPTY')
        );
    }

    public function testOtherMessagesAndOtherRolesCommandsStayAsTheyAre(): void
    {
        $commands = new Commands($this->role(['Magento_Sales::sales_order']), $this->createStub(\Magento\Framework\Module\Manager::class));
        $this->assertSame('/describe 24-UG06', $commands->expand('/describe 24-UG06'), 'no catalog resource');
        $this->assertSame('Show the orders of today.', $commands->expand('/orders today'));
        $this->assertSame('Show the orders.', $commands->expand('/orders'));
        $this->assertSame('What about /orders?', $commands->expand('What about /orders?'));
        $this->assertSame('/nothing here', $commands->expand('/nothing here'));
        $this->assertSame(['orders'], array_keys($commands->allowed()));
    }

    public function testCommandsFromDiXmlJoinTheBuiltInOnes(): void
    {
        $commands = new Commands($this->role([]), $this->createStub(\Magento\Framework\Module\Manager::class), ['hello' => ['label' => 'Greet', 'text' => 'Say hello to {args}.']]);
        $this->assertSame('Say hello to Olena.', $commands->expand('/hello Olena'));
    }

    private function role(array $resources): AuthorizationInterface
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(static fn (string $resource) => in_array($resource, $resources, true));
        return $authorization;
    }
}
