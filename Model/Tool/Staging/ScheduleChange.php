<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Staging;

use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\ModelHtml;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\Staging\Model\Campaigns;
use Zero\Staging\Model\Clock;
use Zero\Staging\Model\Planner;
use Zero\Staging\Model\Target\Pool;

/**
 * schedule_change: a product or category change in a campaign (an existing
 * scheduled one, or a new one with a name, start and end), applied and
 * reverted by Zero Staging at those times.
 */
class ScheduleChange implements ToolInterface
{
    private const TARGETS = ['product', 'category'];

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ModelHtml $html,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function planner(): Planner
    {
        return $this->objectManager->get(Planner::class);
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function campaigns(): Campaigns
    {
        return $this->objectManager->get(Campaigns::class);
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function targets(): Pool
    {
        return $this->objectManager->get(Pool::class);
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function clock(): Clock
    {
        return $this->objectManager->get(Clock::class);
    }

    public function name(): string
    {
        return 'schedule_change';
    }

    public function description(): string
    {
        return 'Schedule a change for a period (Zero Staging): a product (by sku) or category (by entity_id) gets the fields given from the campaign\'s start and the old values back at its end. Either campaign_id of a scheduled campaign (list_campaigns) or a new one: campaign_name, start and end (YYYY-MM-DD HH:MM, store time; no end = stays). Product fields: price, special_price (empty string removes it), status (1 enabled, 2 disabled), name, short_description, description; category: name, description, is_active. Shown to the admin to confirm first.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'campaign_id' => ['type' => 'integer'],
            'campaign_name' => ['type' => 'string'],
            'start' => ['type' => 'string'],
            'end' => ['type' => 'string'],
            'target' => ['type' => 'string', 'enum' => self::TARGETS, 'default' => 'product'],
            'sku' => ['type' => 'string'],
            'entity_id' => ['type' => 'integer'],
            'fields' => ['type' => 'object', 'additionalProperties' => true],
        ], 'required' => ['fields']];
    }

    public function resource(): string
    {
        return 'Zero_Staging::staging';
    }

    public function area(): string
    {
        return 'staging';
    }

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        [$target, $entityId, $fields, $campaign] = $this->parse($input);
        $handler = $this->targets()->get($target);
        $lines = [
            'Campaign' => $campaign['id'] ? $campaign['name'] . ' (#' . $campaign['id'] . ')' : $campaign['name'] . ' (new)',
            'From' => $this->clock()->toInput($campaign['start']),
            'Until' => $campaign['end'] ? $this->clock()->toInput($campaign['end']) : '(no end)',
            ucfirst($target) => $handler->describe($entityId) . ' (#' . $entityId . ')',
        ];
        if ($campaign['id'] && ($existing = $this->campaigns()->findChange((int) $campaign['id'], $target, $entityId, 0))) {
            $lines['Replaces'] = 'this campaign\'s change #' . $existing['change_id'] . ' of the same ' . $target . ' (' . implode(', ', array_keys((array) $existing['data'])) . ')';
        }
        $clashing = $this->campaigns()->conflicts((int) $campaign['id'], $campaign['start'], $campaign['end'], $target, $entityId, 0, array_keys($fields));
        if ($clashing) {
            throw new LocalizedException(__('"%1" changes the same fields of this %2 at the same time. Change other fields, or move one of the campaigns.', implode('", "', $clashing), $target));
        }
        $current = $handler->read($entityId, 0, array_keys($fields));
        foreach ($fields as $field => $value) {
            $old = $current[$field]['value'] ?? null;
            $lines[$handler->fields()[$field]['label'] ?? $field] = ($old === null || $old === '' ? '(empty)' : (string) $old) . ' → ' . ($value === '' ? '(empty)' : (string) $value);
        }

        return ['title' => (string) __('Schedule %1 change(s)', count($fields)), 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        [$target, $entityId, $fields, $campaign] = $this->parse($input);
        $campaignId = (int) $campaign['id'];
        if (!$campaignId) {
            $campaignId = $this->planner()->saveCampaign([
                'name' => $campaign['name'],
                'start' => $this->clock()->toInput($campaign['start']),
                'end' => $campaign['end'] ? $this->clock()->toInput($campaign['end']) : '',
            ], null, 'AI Assistant (admin #' . $context->userId . ')');
        }
        foreach ($fields as $field => $value) {
            if (is_string($value)) {
                $fields[$field] = in_array($field, ['description', 'short_description'], true) ? $this->html->clean($value) : $this->html->text($value);
            }
        }
        $changeId = $this->planner()->saveChange($campaignId, $target, $entityId, 0, $fields);

        return ['campaign_id' => $campaignId, 'change_id' => $changeId, 'target' => $target, 'entity_id' => $entityId, 'fields' => $fields];
    }

    /**
     * @return array{0: string, 1: int, 2: array, 3: array{id: int, name: string, start: string, end: ?string}}
     * @throws LocalizedException
     */
    private function parse(array $input): array
    {
        $target = (string) ($input['target'] ?? 'product');
        if (!in_array($target, self::TARGETS, true)) {
            throw new LocalizedException(__('target must be product or category.'));
        }
        $handler = $this->targets()->get($target);
        $entityId = (int) ($input['entity_id'] ?? 0);
        if ($target === 'product' && !empty($input['sku'])) {
            try {
                $entityId = (int) $this->products->get((string) $input['sku'])->getId();
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('No product with SKU %1.', $input['sku']));
            }
        }
        if (!$entityId || $handler->describe($entityId) === null) {
            throw new LocalizedException(__('Say which %1: sku or entity_id.', $target));
        }
        $fields = array_intersect_key((array) ($input['fields'] ?? []), $handler->fields());
        $unknown = array_diff_key((array) ($input['fields'] ?? []), $handler->fields());
        if ($unknown) {
            throw new LocalizedException(__('Not a field of a %1: %2. Known: %3.', $target, implode(', ', array_keys($unknown)), implode(', ', array_keys($handler->fields()))));
        }
        if (!$fields) {
            throw new LocalizedException(__('Give at least one field to change.'));
        }
        if (!empty($input['campaign_id'])) {
            $campaign = $this->campaigns()->get((int) $input['campaign_id']);
            if (!$campaign) {
                throw new LocalizedException(__('No campaign #%1.', $input['campaign_id']));
            }
            if ($campaign['status'] !== Campaigns::SCHEDULED) {
                throw new LocalizedException(__('Campaign #%1 is %2: only a scheduled one takes changes.', $campaign['campaign_id'], $campaign['status']));
            }

            return [$target, $entityId, $fields, ['id' => (int) $campaign['campaign_id'], 'name' => $campaign['name'], 'start' => $campaign['start_at'], 'end' => $campaign['end_at']]];
        }
        $name = trim((string) ($input['campaign_name'] ?? ''));
        $start = $this->clock()->toUtc((string) ($input['start'] ?? ''));
        $end = trim((string) ($input['end'] ?? '')) === '' ? null : $this->clock()->toUtc((string) $input['end']);
        if ($name === '' || $start === null) {
            throw new LocalizedException(__('A new campaign needs campaign_name and start (YYYY-MM-DD HH:MM).'));
        }
        if (trim((string) ($input['end'] ?? '')) !== '' && $end === null) {
            throw new LocalizedException(__('end is not a date and time (YYYY-MM-DD HH:MM).'));
        }
        if ($start <= $this->clock()->now()) {
            throw new LocalizedException(__('start is in the past.'));
        }
        if ($end !== null && $end <= $start) {
            throw new LocalizedException(__('end must be after start.'));
        }

        return [$target, $entityId, $fields, ['id' => 0, 'name' => $name, 'start' => $start, 'end' => $end]];
    }
}
