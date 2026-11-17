<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager as ModuleManager;
use Zero\AiAssistant\Model\Tool\InputAclInterface;
use Zero\AiAssistant\Model\Tool\ToolInterface;

/**
 * The assistant's tools (etc/di.xml), those the signed-in admin user's role
 * allows: what Claude is offered, what the "What I can do" panel lists, and
 * what may run.
 *
 * `tools` is a di.xml pool (item name => tool, ordered by the item's sortOrder,
 * merged from every module's di.xml). `requires` maps an item name to the module
 * that must be enabled for the tool to be offered: a tool of another Zero
 * module is registered as a \Proxy item, so while that module is off the tool is
 * not even built.
 */
class Tools
{
    public const AREAS = ['catalog', 'cms', 'sales', 'system', 'blog', 'quotes', 'returns', 'staging', 'reviews', 'banners'];
    private const AREA = 'Zero_AiAssistant::area_';

    /** @var ToolInterface[]|null */
    private ?array $all = null;

    /**
     * @param ToolInterface[] $tools Item name => tool, in di.xml sortOrder
     * @param array<string, string> $requires Item name => module name that must be enabled
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly ModuleManager $moduleManager,
        private readonly array $tools = [],
        private readonly array $requires = []
    ) {
    }

    /**
     * @return ToolInterface[] the pool's tools whose module (if any) is enabled, in pool order
     */
    private function all(): array
    {
        if ($this->all === null) {
            $this->all = [];
            foreach ($this->tools as $key => $tool) {
                if (!$tool instanceof ToolInterface) {
                    continue;
                }
                $module = $this->requires[$key] ?? null;
                if ($module === null || $module === '' || $this->moduleManager->isEnabled((string) $module)) {
                    $this->all[] = $tool;
                }
            }
        }

        return $this->all;
    }

    /**
     * The tools the role may use: the admin resource the same work needs,
     * and, once the role is limited to areas (any AI Assistant area ticked
     * in System > User Roles), only those areas, changing tools only where
     * "change" is ticked too.
     *
     * @return array<string, ToolInterface> By name
     */
    public function allowed(): array
    {
        $allowed = [];
        $limited = $this->isLimited();
        foreach ($this->all() as $tool) {
            if (!$this->authorization->isAllowed($tool->resource())) {
                continue;
            }
            if ($limited && (!$this->authorization->isAllowed(self::AREA . $tool->area())
                || ($tool->changes() && !$this->authorization->isAllowed(self::AREA . $tool->area() . '_change')))) {
                continue;
            }
            $allowed[$tool->name()] = $tool;
        }

        return $allowed;
    }

    /**
     * Whether the role is limited to areas: it has at least one.
     */
    public function isLimited(): bool
    {
        foreach (self::AREAS as $area) {
            if ($this->authorization->isAllowed(self::AREA . $area)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every changing tool of the enabled modules, whatever the role: the
     * names a setting can list.
     *
     * @return string[]
     */
    public function changingNames(): array
    {
        $names = [];
        foreach ($this->all() as $tool) {
            if ($tool->changes()) {
                $names[] = $tool->name();
            }
        }
        sort($names);
        return $names;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->allowed()[$name] ?? null;
    }

    /**
     * The records' own resource checked, for a tool that has one (InputAclInterface):
     * before a preview, and again before a confirmed run (the records may have changed).
     *
     * @throws LocalizedException when the role lacks it
     */
    public function authorize(ToolInterface $tool, array $input): void
    {
        if (!$tool instanceof InputAclInterface) {
            return;
        }
        $resource = $tool->resourceFor($input);
        if (!$this->authorization->isAllowed($resource)) {
            throw new LocalizedException(
                __('Your role doesn\'t allow %1 on these records: it needs %2.', $tool->name(), $resource)
            );
        }
    }

    /**
     * The Messages API's `tools`.
     */
    public function definitions(): array
    {
        return array_values(array_map(static fn (ToolInterface $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description() . ($tool->changes() ? '' : ' Read only: runs at once.'),
            'input_schema' => $tool->schema(),
        ], $this->allowed()));
    }
}
