<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Staging;

use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;
use Zero\Staging\Model\Campaigns;
use Zero\Staging\Model\Clock;

/**
 * list_campaigns: scheduled and live campaigns with their changes.
 */
class ListCampaigns extends ReadTool
{
    public function __construct(private readonly ObjectManagerInterface $objectManager)
    {
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
    private function clock(): Clock
    {
        return $this->objectManager->get(Clock::class);
    }

    public function name(): string
    {
        return 'list_campaigns';
    }

    public function description(): string
    {
        return 'Scheduled changes (Zero Staging): campaigns with status scheduled, active (live) or completed, their start and end in store time, and each change (target, what it changes, status). Use the campaign id with schedule_change to add to a scheduled one.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['upcoming', 'completed', 'all'], 'default' => 'upcoming', 'description' => 'upcoming: scheduled and live'],
        ]];
    }

    public function resource(): string
    {
        return 'Zero_Staging::staging';
    }

    public function area(): string
    {
        return 'staging';
    }

    public function run(array $input, Context $context): array
    {
        $statuses = match ((string) ($input['status'] ?? 'upcoming')) {
            'completed' => [Campaigns::COMPLETED],
            'all' => null,
            default => [Campaigns::SCHEDULED, Campaigns::ACTIVE],
        };
        $rows = [];
        foreach ($this->campaigns()->all($statuses) as $campaign) {
            $id = (int) $campaign['campaign_id'];
            $rows[] = [
                'id' => $id,
                'name' => $campaign['name'],
                'status' => $campaign['status'],
                'start' => $this->clock()->toInput($campaign['start_at']),
                'end' => $this->clock()->toInput($campaign['end_at']),
                'changes' => array_map(static fn (array $c) => [
                    'id' => (int) $c['change_id'],
                    'target' => $c['target'],
                    'entity_id' => (int) $c['entity_id'],
                    'label' => $c['label'],
                    'data' => is_string($c['data']) ? (json_decode($c['data'], true) ?: $c['data']) : $c['data'],
                    'status' => $c['status'],
                ], $this->campaigns()->changes($id)),
            ];
        }

        return ['campaigns' => $rows, 'now' => $this->clock()->toInput($this->clock()->now())];
    }
}
