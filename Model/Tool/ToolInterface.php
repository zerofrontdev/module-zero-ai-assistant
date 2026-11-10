<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool;

use Magento\Framework\Exception\LocalizedException;

/**
 * Something the assistant can do, offered to Claude only when the admin
 * user's role has resource(). A tool that changes() data runs only after the
 * admin confirms what preview() shows.
 */
interface ToolInterface
{
    /** The name Claude calls it by. */
    public function name(): string;

    /** For Claude: what it does, when to use it. */
    public function description(): string;

    /** JSON Schema of its input. */
    public function schema(): array;

    /** The ACL resource the same work needs in the admin. */
    public function resource(): string;

    /**
     * The assistant's own area (catalog, cms, sales, system, blog, quotes,
     * returns, staging, reviews, banners): a role may be limited to areas
     * (System > User Roles > AI Assistant), and to looking up only.
     */
    public function area(): string;

    public function changes(): bool;

    /**
     * For a changing tool: what the admin confirms, as a title and label => value
     * lines; it validates the input (throws what is wrong).
     *
     * @return array{title: string, lines: array<string, string>}
     * @throws LocalizedException
     */
    public function preview(array $input, Context $context): array;

    /**
     * Does it; the answer goes to Claude as JSON.
     *
     * @throws LocalizedException
     */
    public function run(array $input, Context $context): array;
}
