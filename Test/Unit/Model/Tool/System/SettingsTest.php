<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Tool\System;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Tool\System\Settings;

/**
 * Only the listed settings, and only values of the right kind.
 */
class SettingsTest extends TestCase
{
    public function testValuesAreCleanedByKind(): void
    {
        $this->assertSame('Kids Caramel', Settings::clean('general/store_information/name', ' Kids Caramel '));
        $this->assertSame('+380 67 123 45 67', Settings::clean('general/store_information/phone', '+380 67 123 45 67'));
        $this->assertSame('UA', Settings::clean('general/store_information/country_id', 'ua'));
        $this->assertSame('Europe/Kyiv', Settings::clean('general/locale/timezone', 'Europe/Kyiv'));
        $this->assertSame('1', Settings::clean('contact/contact/enabled', 'yes'));
        $this->assertSame('0', Settings::clean('contact/contact/enabled', false));
        $this->assertSame('', Settings::clean('general/store_information/hours', ''), 'an empty string clears');
    }

    public function testWrongValuesAreRefused(): void
    {
        foreach ([
            ['trans_email/ident_general/email', 'not-an-email'],
            ['general/store_information/country_id', 'Ukraine'],
            ['general/locale/timezone', 'Kyiv'],
            ['general/locale/code', 'ukrainian'],
            ['shipping/origin/region_id', 'Kyiv oblast'],
        ] as [$path, $value]) {
            try {
                Settings::clean($path, $value);
                $this->fail($path . ' took ' . $value);
            } catch (LocalizedException $e) {
                $this->assertStringContainsString('not a valid', $e->getMessage());
            }
        }
    }

    public function testOnlyListedPathsAndRequiredOnesAreListed(): void
    {
        try {
            Settings::clean('payment/braintree/private_key', 'x');
            $this->fail('a payment key took a value');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('not a setting the assistant may change', $e->getMessage());
        }
        foreach (Settings::REQUIRED as $path) {
            $this->assertArrayHasKey($path, Settings::PATHS);
        }
    }
}
