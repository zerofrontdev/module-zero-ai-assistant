<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Tool;

use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Tool\System\Settings;

/**
 * The settings tools follow Stores > Configuration's own permissions: a
 * section the role may not open is neither read nor changed.
 */
class SettingsAclTest extends TestCase
{
    public function testEveryPathHasItsSectionsResourceAndTheRoleDecides(): void
    {
        foreach (array_keys(Settings::PATHS) as $path) {
            $this->assertArrayHasKey(strtok($path, '/'), Settings::SECTION_RESOURCES, $path . ' has no section resource');
        }
        $generalOnly = $this->createStub(AuthorizationInterface::class);
        $generalOnly->method('isAllowed')->willReturnCallback(static fn (string $r) => $r === 'Magento_Config::config_general');
        $this->assertTrue(Settings::mayUse('general/store_information/name', $generalOnly));
        $this->assertFalse(Settings::mayUse('trans_email/ident_sales/email', $generalOnly));
        $this->assertFalse(Settings::mayUse('payment/checkmo/active', $generalOnly), 'a section not on the list');
    }
}
