<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\System;

use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Indexer\Model\Indexer\CollectionFactory as IndexerCollectionFactory;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;

/**
 * reindex: a full rebuild of the indexes named (the invalid ones by
 * default), as bin/magento indexer:reindex does.
 *
 * Outside the adminhtml and crontab config scopes (the Claude connector runs in frontend) the catalog rule indexes are
 * not rebuilt here: Magento_CatalogRuleConfigurable declares the plugins that give a configurable's rule discount to
 * its variants (on CatalogRule\Model\Rule and ProductRuleIndexer) for adminhtml and crontab only, so a rebuild in
 * this request would leave the variants without the discount. They are marked invalid instead and cron's
 * indexer_reindex_all_invalid (group index, crontab scope) rebuilds them within a few minutes. catalog_product_price
 * needs none of those plugins and is rebuilt here as before, unless a catalog rule index of the same call is
 * queued: it depends on catalogrule_rule (invalidating that one invalidates it too) and must be rebuilt after it.
 */
class Reindex implements ToolInterface
{
    /** Indexes whose full rebuild needs the adminhtml/crontab plugin list. */
    private const CRON_ONLY = ['catalogrule_rule', 'catalogrule_product'];

    /** Indexes rebuilt from the catalog rule tables: queued with them when one of them is queued. */
    private const AFTER_RULES = ['catalog_product_price'];

    /** Config scopes that carry Magento_CatalogRuleConfigurable's plugins. */
    private const RULE_SCOPES = ['adminhtml', 'crontab'];

    public function __construct(
        private readonly IndexerCollectionFactory $indexers,
        private readonly IndexerRegistry $registry,
        private readonly ScopeInterface $configScope
    ) {
    }

    public function name(): string
    {
        return 'reindex';
    }

    public function description(): string
    {
        return 'Rebuild indexes in full: the ids given (from store_status), or with none given the invalid ones. Shown to the admin to confirm first. Takes a while on a large catalog. Not needed after an ordinary change: indexes in schedule mode catch up by cron within a minute, those in save mode at once. Through the connector the catalog rule indexes (and the price index named with them) are queued for cron instead of rebuilt.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'indexers' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Indexer ids; none: the invalid ones'],
        ]];
    }

    public function resource(): string
    {
        return 'Magento_Indexer::index';
    }

    public function area(): string
    {
        return 'system';
    }

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        $ids = $this->ids($input);
        $titles = [];
        foreach ($this->indexers->create()->getItems() as $indexer) {
            if (in_array($indexer->getId(), $ids, true)) {
                $titles[] = $indexer->getTitle() . ' (' . $indexer->getId() . ')';
            }
        }

        return ['title' => (string) __('Rebuild %1 index(es)', count($ids)), 'lines' => [(string) __('Indexes') => implode(', ', $titles)]];
    }

    public function run(array $input, Context $context): array
    {
        $ids = $this->ids($input);
        $queued = $this->queued($ids);
        foreach ($queued as $id) {
            $this->registry->get($id)->invalidate();
        }
        $done = [];
        foreach ($ids as $id) {
            if (in_array($id, $queued, true)) {
                $done[$id] = (string) __('Queued: it will be rebuilt by the cron within a few minutes.');
                continue;
            }
            $started = microtime(true);
            $this->registry->get($id)->reindexAll();
            $done[$id] = round(microtime(true) - $started, 1) . ' s';
        }

        return ['reindexed' => $done];
    }

    /**
     * The ids of $ids to leave to cron: none in the adminhtml or crontab scope.
     *
     * @param string[] $ids
     * @return string[]
     */
    private function queued(array $ids): array
    {
        if (in_array($this->configScope->getCurrentScope(), self::RULE_SCOPES, true)) {
            return [];
        }
        $rules = array_values(array_intersect($ids, self::CRON_ONLY));
        if (!$rules) {
            return [];
        }

        return array_values(array_merge($rules, array_intersect($ids, self::AFTER_RULES)));
    }

    /**
     * @return string[]
     * @throws LocalizedException
     */
    private function ids(array $input): array
    {
        $known = [];
        $invalid = [];
        foreach ($this->indexers->create()->getItems() as $indexer) {
            $known[] = $indexer->getId();
            if ($indexer->isInvalid()) {
                $invalid[] = $indexer->getId();
            }
        }
        $wanted = array_values(array_unique(array_map('strval', (array) ($input['indexers'] ?? []))));
        if (!$wanted) {
            if (!$invalid) {
                throw new LocalizedException(__('Every index is valid; name the ones to rebuild anyway.'));
            }
            return $invalid;
        }
        $unknown = array_diff($wanted, $known);
        if ($unknown) {
            throw new LocalizedException(__('No index %1. Known: %2.', implode(', ', $unknown), implode(', ', $known)));
        }

        return $wanted;
    }
}
