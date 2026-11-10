<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool;

/**
 * A tool that only reads: run at once, nothing to confirm.
 */
abstract class ReadTool implements ToolInterface
{
    public function changes(): bool
    {
        return false;
    }

    public function preview(array $input, Context $context): array
    {
        return ['title' => '', 'lines' => []];
    }

    /**
     * HTML as short plain text for Claude.
     */
    protected function plain(?string $html, int $max = 600): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace(['<br', '</p>', '</li>'], ["\n<br", "</p>\n", "</li>\n"], (string) $html))));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}
