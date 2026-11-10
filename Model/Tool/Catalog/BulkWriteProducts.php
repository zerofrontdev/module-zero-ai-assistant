<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Catalog;

use Magento\Framework\Exception\LocalizedException;
use Zero\AiAssistant\Model\Bulk\Bulk;
use Zero\AiAssistant\Model\Bulk\Fields;
use Zero\AiAssistant\Model\Bulk\Selector;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;

/**
 * Texts for many products at once (descriptions, short descriptions, meta
 * titles and descriptions), written one product at a time in the
 * background after the admin confirms the list; every old text kept, so
 * the whole job can be undone from its card.
 */
class BulkWriteProducts implements ToolInterface
{
    public function __construct(
        private readonly Selector $selector,
        private readonly Bulk $bulk,
        private readonly Conversations $conversations
    ) {
    }

    public function name(): string
    {
        return 'bulk_write_products';
    }

    public function description(): string
    {
        return 'Write texts (description, short_description, meta_title, meta_description) for several products at once, in the background, after the admin confirms the list: '
            . 'by SKUs, a category id, a word in the name, and/or only_empty (only products where those fields are empty). brief: what to write and how (language, tone, length, what to stress), '
            . 'as agreed with the admin on samples you showed first. Every old text is kept: the job can be undone. Up to ' . $this->bulk->max() . ' products a job.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'skus' => ['type' => 'array', 'items' => ['type' => 'string']],
            'category_id' => ['type' => 'integer'],
            'name_contains' => ['type' => 'string'],
            'only_empty' => ['type' => 'boolean'],
            'fields' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(Fields::ALL)]],
            'brief' => ['type' => 'string'],
        ], 'required' => ['fields', 'brief']];
    }

    public function resource(): string
    {
        return 'Magento_Catalog::products';
    }

    public function area(): string
    {
        return 'catalog';
    }

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        [$fields, $products] = $this->resolve($input);
        $names = array_map(static fn ($p) => $p['sku'] . ' ' . $p['name'], array_slice(array_values($products), 0, 8));
        return [
            'title' => (string) __('Write %1 for %2 products', Fields::label($fields), count($products)),
            'lines' => [
                (string) __('Products') => implode('; ', $names) . (count($products) > 8 ? '; … (' . (count($products) - 8) . ' more)' : ''),
                (string) __('Brief') => mb_substr((string) $input['brief'], 0, 400),
                (string) __('How') => (string) __('One product at a time in the background; the old texts are kept, and Undo puts them back.'),
            ],
        ];
    }

    public function run(array $input, Context $context): array
    {
        [$fields, $products] = $this->resolve($input);
        $action = $context->actionId ? $this->conversations->action($context->actionId, $context->userId) : null;
        if ($action === null) {
            throw new LocalizedException(__('The confirmed change is missing.'));
        }
        $jobId = $this->bulk->start($action, $fields, trim((string) $input['brief']), $products);
        return ['job_id' => $jobId, 'products' => count($products), 'status' => 'writing in the background'];
    }

    /**
     * @return array{0: string[], 1: array<int, array{id: int, sku: string, name: string}>}
     * @throws LocalizedException
     */
    private function resolve(array $input): array
    {
        $fields = Fields::known((array) ($input['fields'] ?? []));
        if (!$fields) {
            throw new LocalizedException(__('Say which texts to write: %1.', implode(', ', array_keys(Fields::ALL))));
        }
        if (trim((string) ($input['brief'] ?? '')) === '') {
            throw new LocalizedException(__('Give a brief: what to write, in which language and tone.'));
        }
        $max = $this->bulk->max();
        $products = $this->selector->select($input, $fields, $max);
        if (!$products) {
            throw new LocalizedException(__('No product matches.'));
        }
        if (count($products) > $max) {
            throw new LocalizedException(__('More than %1 products match: narrow it down (a category, a word in the name) or raise Stores > Configuration > Zero > AI Assistant > Bulk Jobs > Products per Job.', $max));
        }
        return [$fields, $products];
    }
}
