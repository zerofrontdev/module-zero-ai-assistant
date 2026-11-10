<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Tool;

use PHPUnit\Framework\TestCase;

/**
 * The tools for other Zero modules may sit in a store without that module, and `setup:di:compile` needs every
 * constructor type to exist: so no constructor of a tool takes a class of an optional module (it fetches it when
 * the tool runs, the module being enabled).
 */
class OptionalModuleToolsTest extends TestCase
{
    private const OPTIONAL = ['Blog', 'Banner', 'QuoteRequest', 'Returns', 'Reviews', 'NovaPoshta', 'Ukrposhta', 'Checkbox', 'Staging'];

    public function testNoToolConstructorTakesAClassOfAnOptionalModule(): void
    {
        $checked = 0;
        foreach (self::OPTIONAL as $module) {
            foreach (glob(dirname(__DIR__, 4) . '/Model/Tool/' . $module . '/*.php') as $file) {
                $class = 'Zero\\AiAssistant\\Model\\Tool\\' . $module . '\\' . basename($file, '.php');
                $constructor = (new \ReflectionClass($class))->getConstructor();
                foreach ($constructor ? $constructor->getParameters() : [] as $parameter) {
                    $type = (string) $parameter->getType();
                    $this->assertDoesNotMatchRegularExpression(
                        '/^\\??Zero\\\\(' . implode('|', self::OPTIONAL) . ')\\\\/',
                        $type,
                        $class . ' takes ' . $type
                    );
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(20, $checked);
    }
}
