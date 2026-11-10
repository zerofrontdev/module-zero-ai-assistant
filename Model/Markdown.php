<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model;

/**
 * The Markdown Claude answers in, as HTML: paragraphs, headings, lists,
 * tables, code, bold, italics, links (http(s) only). Everything is escaped
 * first: no HTML from the answer reaches the page.
 */
class Markdown
{
    public function toHtml(string $markdown): string
    {
        $lines = preg_split('/\R/u', trim($markdown)) ?: [];
        $html = '';
        $paragraph = [];
        $list = null;
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (str_starts_with(trim($line), '```')) {
                $html .= $this->flush($paragraph, $list);
                $code = [];
                for ($i++; $i < $count && !str_starts_with(trim($lines[$i]), '```'); $i++) {
                    $code[] = $lines[$i];
                }
                $html .= '<pre><code>' . $this->escape(implode("\n", $code)) . '</code></pre>';
                continue;
            }
            if (str_starts_with(trim($line), '|') && isset($lines[$i + 1]) && preg_match('/^\s*\|?\s*:?-{2,}/', $lines[$i + 1])) {
                $html .= $this->flush($paragraph, $list);
                $rows = [$line];
                for ($i += 2; $i < $count && str_starts_with(trim($lines[$i]), '|'); $i++) {
                    $rows[] = $lines[$i];
                }
                $i--;
                $html .= $this->table($rows);
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
                $html .= $this->flush($paragraph, $list);
                $level = min(6, strlen($m[1]) + 2);
                $html .= "<h{$level}>" . $this->inline($m[2]) . "</h{$level}>";
                continue;
            }
            if (preg_match('/^\s*([-*•]|\d+[.)])\s+(.*)$/u', $line, $m)) {
                $type = ctype_digit($m[1][0]) ? 'ol' : 'ul';
                if ($list !== $type) {
                    $html .= $this->flush($paragraph, $list);
                    $html .= "<{$type}>";
                    $list = $type;
                }
                $html .= '<li>' . $this->inline($m[2]) . '</li>';
                continue;
            }
            if (trim($line) === '') {
                $html .= $this->flush($paragraph, $list);
                continue;
            }
            if ($list !== null) {
                $html .= $this->flush($paragraph, $list);
            }
            $paragraph[] = $line;
        }

        return $html . $this->flush($paragraph, $list);
    }

    private function flush(array &$paragraph, ?string &$list): string
    {
        $html = $list !== null ? "</{$list}>" : '';
        $list = null;
        if ($paragraph) {
            $html .= '<p>' . implode('<br>', array_map([$this, 'inline'], $paragraph)) . '</p>';
            $paragraph = [];
        }

        return $html;
    }

    private function table(array $rows): string
    {
        $cells = static fn (string $row) => array_map('trim', explode('|', trim(trim($row), '|')));
        $html = '<div class="zero-ai-table"><table><thead><tr>';
        foreach ($cells(array_shift($rows)) as $cell) {
            $html .= '<th>' . $this->inline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($cells($row) as $cell) {
                $html .= '<td>' . $this->inline($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private function inline(string $text): string
    {
        $text = $this->escape($text);
        $text = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/u', '<em>$1</em>', $text);

        return (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u',
            static fn ($m) => '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>',
            $text
        );
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
