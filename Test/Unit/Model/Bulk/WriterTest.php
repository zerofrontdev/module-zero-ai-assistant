<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Bulk;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Bulk\Writer;

/**
 * The model's answer read: the JSON wherever it is, only the fields asked
 * for, each made safe.
 */
class WriterTest extends TestCase
{
    public function testReadsTheJsonInsideAnyWrapping(): void
    {
        $answer = "Here you go:\n```json\n{\"description\": \"<p onclick=\\\"x()\\\">Tough bottle.</p><script>bad()</script>\", \"meta_title\": \"<b>Bottle</b>\", \"name\": \"ignored\"}\n```";
        $this->assertSame(
            ['description' => '<p>Tough bottle.</p>', 'meta_title' => 'Bottle'],
            Writer::parse($answer, ['description', 'meta_title'])
        );
    }

    public function testAnAnswerWithoutTheFieldsIsAnError(): void
    {
        $this->expectException(LocalizedException::class);
        Writer::parse('{"name": "x"}', ['description']);
    }

    public function testAnAnswerThatIsNoJsonIsAnError(): void
    {
        $this->expectException(LocalizedException::class);
        Writer::parse('Sorry, I cannot.', ['description']);
    }
}
