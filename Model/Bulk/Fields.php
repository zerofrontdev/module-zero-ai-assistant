<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Bulk;

use Zero\AiAssistant\Model\Html;

/**
 * The product texts a bulk job writes, and how each is made safe.
 */
class Fields
{
    /** field => max length (0: HTML, no limit) */
    public const ALL = [
        'description' => 0,
        'short_description' => 0,
        'meta_title' => 70,
        'meta_description' => 160,
    ];

    /**
     * For people: "descriptions, meta titles".
     *
     * @param string[] $fields
     */
    public static function label(array $fields): string
    {
        $names = [
            'description' => (string) __('descriptions'),
            'short_description' => (string) __('short descriptions'),
            'meta_title' => (string) __('meta titles'),
            'meta_description' => (string) __('meta descriptions'),
        ];
        return implode(', ', array_map(static fn (string $f) => $names[$f] ?? $f, $fields));
    }

    /**
     * @param string[] $fields
     * @return string[] the known ones, in a stable order
     */
    public static function known(array $fields): array
    {
        return array_values(array_intersect(array_keys(self::ALL), $fields));
    }

    public static function clean(string $field, string $value): string
    {
        $max = self::ALL[$field] ?? 255;
        return $max === 0 ? Html::description($value) : Html::plain($value, $max);
    }
}
