<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model;

/**
 * What the model writes into the store, made safe before it is saved: a
 * page, a block, a product's or category's description, a post. The model
 * may have read text someone planted (a review, an order comment), so what
 * it writes is untrusted:
 *
 * - nothing that runs: no script, iframe, object, form, svg; no on*
 *   attribute; links and sources only http(s), mailto, tel or relative; no
 *   CSS that loads anything (url(), @import);
 * - no Magento directive ({{block}}, {{widget}}, {{var}}, {{config}}…) except
 *   {{media url}} and {{store url}}, the assistant's own {{upload:N}}, and
 *   the ones already in the text being changed (a page's widget stays when
 *   the model edits the page around it).
 *
 * Markup, classes, data-* attributes and Page Builder's own styles stay:
 * editing a Page Builder page keeps its layout.
 */
class ModelHtml
{
    private const DROP = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'meta', 'link',
        'form', 'input', 'button', 'textarea', 'select', 'option', 'svg', 'math', 'template', 'noscript', 'portal', 'title', 'head'];
    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'xlink:href', 'poster', 'background', 'data', 'cite', 'longdesc', 'srcset', 'ping'];
    private const SAFE_DIRECTIVE = '/^\{\{(media|store)\s+(url|direct_url)=("[^"{}]*"|\'[^\'{}]*\'|[^\s{}"\']*)\s*\}\}$/i';
    private const UPLOAD = '/^\{\{\s*upload:\s*\d+\s*\}\}$/';
    // image-set(), image(), cross-fade() and src() take a file name without url(.
    private const UNSAFE_CSS = '/url\s*\(|@import|expression\s*\(|javascript:|behavior\s*:|-moz-binding|image-set\s*\(|\bimage\s*\(|cross-fade\s*\(|\bsrc\s*\(/i';

    /**
     * @param string $existing the text being replaced: its directives may stay
     */
    public function clean(string $html, string $existing = ''): string
    {
        if (trim($html) === '') {
            return $html;
        }
        $kept = [];
        $nonce = bin2hex(random_bytes(6));
        $html = $this->directives($html, $existing, $kept, $nonce);
        $html = $this->sanitize($html);
        // A directive put together by what was removed ({<script></script>{var x}}) is text, not a directive.
        $html = str_replace(['{{', '}}'], ['{ {', '} }'], $html);
        return strtr($html, $kept);
    }

    /**
     * A plain field (a name, a title, a meta text): only the directives go.
     */
    public function text(string $text, string $existing = ''): string
    {
        $kept = [];
        $text = $this->directives($text, $existing, $kept, bin2hex(random_bytes(6)));
        return strtr(str_replace(['{{', '}}'], ['{ {', '} }'], $text), $kept);
    }

    /**
     * Whether a link the model gives is one to keep: http(s), mailto, tel, or within the site.
     */
    public static function isSafeUrl(string $url): bool
    {
        $url = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (!preg_match('/^([a-z][a-z0-9+.\-]*):/', $url, $m)) {
            return true;
        }
        return in_array($m[1], ['http', 'https', 'mailto', 'tel'], true);
    }

    /**
     * What the admin should see before confirming HTML: its text, and where its links and images go.
     *
     * @return array<string, string>
     */
    /**
     * Whether CSS could load something. CSS reads \75rl( as url( and @\69mport as @import,
     * so the escapes (and comments) are resolved before the check.
     */
    private static function unsafeCss(string $css): bool
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $css = (string) preg_replace_callback(
            '/\\\\(?:([0-9a-f]{1,6})[ \t\r\n\f]?|([^\r\n\f0-9a-f]))/i',
            static fn (array $m) => $m[1] !== ''
                ? (hexdec($m[1]) > 0 && hexdec($m[1]) <= 0x10FFFF ? mb_chr((int) hexdec($m[1]), 'UTF-8') : '')
                : ($m[2] ?? ''),
            $css
        );

        return (bool) preg_match(self::UNSAFE_CSS, $css);
    }

    public function summary(string $label, string $html): array
    {
        $lines = [$label => Html::plain($html, 400) . ' (' . mb_strlen($html) . ' characters)'];
        preg_match_all('/\b(?:href|src)\s*=\s*["\']?(https?:)?\/\/([^\/"\'\s>]+)/i', $html, $m);
        $hosts = array_values(array_unique(array_map('strtolower', $m[2])));
        if ($hosts) {
            $lines[$label . ': links and images to'] = implode(', ', $hosts);
        }
        return $lines;
    }

    /**
     * @param array<string, string> $kept sentinel => the directive it stands for
     */
    private function directives(string $text, string $existing, array &$kept, string $nonce): string
    {
        return (string) preg_replace_callback('/\{\{.*?\}\}/s', function (array $m) use ($existing, &$kept, $nonce) {
            $directive = $m[0];
            if (!preg_match(self::SAFE_DIRECTIVE, $directive) && !preg_match(self::UPLOAD, $directive)
                && ($existing === '' || !str_contains($existing, $directive))) {
                return '';
            }
            $sentinel = 'zero-directive-' . $nonce . '-' . count($kept);
            $kept[$sentinel] = $directive;
            return $sentinel;
        }, $text);
    }

    private function sanitize(string $html): string
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="zero-model-html">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('zero-model-html');
        if ($root === null) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $this->walk($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true) || ($tag === 'style' && self::unsafeCss((string) $child->textContent))) {
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = (string) $attribute->nodeValue;
                $unsafe = str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'formaction'], true)
                    || ($name === 'style' && self::unsafeCss($value))
                    || ($name === 'srcset' && array_filter(explode(',', $value), static fn ($part) => !self::isSafeUrl(trim(explode(' ', trim($part))[0]))))
                    || (in_array($name, self::URL_ATTRIBUTES, true) && $name !== 'srcset' && !self::isSafeUrl($value));
                if ($unsafe) {
                    $child->removeAttribute($attribute->nodeName);
                }
            }
            $this->walk($child);
        }
    }
}
