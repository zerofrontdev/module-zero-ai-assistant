<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model;

/**
 * Text the model wrote, made safe to store on a product: a description
 * keeps only plain formatting tags without attributes (no scripts, styles,
 * links or event handlers, whatever the model was talked into); plain
 * fields lose all tags and get their length limit.
 */
class Html
{
    private const TAGS = ['p', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'br', 'h2', 'h3', 'h4'];

    public static function description(string $html): string
    {
        // Whole elements whose content is code, gone with the content.
        $html = (string) preg_replace('#<(script|style|iframe|object|embed|template|svg|math)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = strip_tags($html, self::TAGS);
        // Any attribute on the tags that stay (onclick, style, href…).
        $html = (string) preg_replace('#<(/?)(' . implode('|', self::TAGS) . ')\b[^>]*?(/?)>#i', '<$1$2$3>', $html);
        return trim($html);
    }

    public static function plain(string $text, int $max = 0): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($max > 0 && mb_strlen($text) > $max) {
            $text = rtrim(mb_substr($text, 0, $max - 1), " ,.;:-") . '…';
        }
        return $text;
    }
}
