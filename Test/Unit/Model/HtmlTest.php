<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Html;

/**
 * What the model writes, made safe before it lands on a product.
 */
class HtmlTest extends TestCase
{
    public function testADescriptionKeepsPlainFormattingOnly(): void
    {
        $this->assertSame(
            '<p>Light and <strong>tough</strong>.</p><ul><li>750 ml</li></ul>',
            Html::description('<p class="x" onclick="steal()">Light and <strong style="color:red">tough</strong>.</p><ul><li>750 ml</li></ul>')
        );
    }

    public function testScriptsStylesFramesAndLinksAreGone(): void
    {
        $html = Html::description('<p>Hi</p><script>fetch("//evil")</script><style>body{}</style><iframe src="//x"></iframe><a href="javascript:x">link</a><img src=x onerror=alert(1)>');
        $this->assertSame('<p>Hi</p>link', $html);
    }

    public function testPlainFieldsLoseTagsAndKeepTheirLimit(): void
    {
        $this->assertSame('Affirm Water Bottle – 750 ml', Html::plain('<b>Affirm Water Bottle</b> &ndash; 750 ml'));
        $text = Html::plain(str_repeat('word ', 50), 70);
        $this->assertLessThanOrEqual(70, mb_strlen($text));
        $this->assertStringEndsWith('…', $text);
    }
}
