<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Markdown;

class MarkdownTest extends TestCase
{
    public function testTablesListsAndInline(): void
    {
        $html = (new Markdown())->toHtml("Orders:\n\n| # | Total |\n|---|---|\n| 1001 | **540 ₴** |\n\n- one\n- `two`");

        $this->assertStringContainsString('<p>Orders:</p>', $html);
        $this->assertStringContainsString('<th>#</th><th>Total</th>', $html);
        $this->assertStringContainsString('<td>1001</td><td><strong>540 ₴</strong></td>', $html);
        $this->assertStringContainsString('<ul><li>one</li><li><code>two</code></li></ul>', $html);
    }

    public function testHtmlIsEscapedAndOnlyWebLinksKept(): void
    {
        $html = (new Markdown())->toHtml('<script>alert(1)</script> [x](javascript:alert(1)) [ok](https://example.com)');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('<a href="https://example.com" target="_blank" rel="noopener">ok</a>', $html);
    }
}
