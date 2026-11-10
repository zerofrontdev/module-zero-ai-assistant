<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Tool\System;

use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Indexer\Model\Indexer\Collection;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\System\Reindex;

/**
 * reindex: outside adminhtml/crontab the catalog rule indexes (and the price index named with them) are invalidated
 * for cron instead of rebuilt without Magento_CatalogRuleConfigurable's plugins; every other case rebuilds as before.
 */
class ReindexTest extends TestCase
{
    private const QUEUED = 'Queued: it will be rebuilt by the cron within a few minutes.';

    /** @var array<string, IndexerInterface&MockObject> */
    private array $byId = [];

    public function testFrontendScopeQueuesTheCatalogRuleIndexesForCron(): void
    {
        $this->indexer('catalogrule_rule', 'never', 'once');
        $this->indexer('catalogrule_product', 'never', 'once');

        $result = $this->tool('frontend')->run(['indexers' => ['catalogrule_rule', 'catalogrule_product']], $this->context());

        $this->assertSame(['reindexed' => [
            'catalogrule_rule' => self::QUEUED,
            'catalogrule_product' => self::QUEUED,
        ]], $result);
    }

    public function testFrontendScopeQueuesThePriceIndexNamedWithARuleIndex(): void
    {
        $this->indexer('catalog_product_price', 'never', 'once');
        $this->indexer('catalogrule_rule', 'never', 'once');

        $result = $this->tool('frontend')->run(['indexers' => ['catalog_product_price', 'catalogrule_rule']], $this->context());

        $this->assertSame(['catalog_product_price' => self::QUEUED, 'catalogrule_rule' => self::QUEUED], $result['reindexed']);
    }

    public function testFrontendScopeRebuildsOtherIndexesAsBefore(): void
    {
        $this->indexer('catalog_product_price', 'once', 'never');
        $this->indexer('catalogsearch_fulltext', 'once', 'never');
        $this->indexer('catalogrule_rule', 'never', 'never');

        $result = $this->tool('frontend')->run(['indexers' => ['catalog_product_price', 'catalogsearch_fulltext']], $this->context());

        $this->assertSame(['catalog_product_price', 'catalogsearch_fulltext'], array_keys($result['reindexed']));
        foreach ($result['reindexed'] as $took) {
            $this->assertMatchesRegularExpression('/^\d+(\.\d)? s$/', $took);
        }
    }

    public function testFrontendScopeQueuesOnlyTheRuleIndexAmongOthers(): void
    {
        $this->indexer('catalogrule_product', 'never', 'once');
        $this->indexer('catalogsearch_fulltext', 'once', 'never');

        $result = $this->tool('webapi_rest')->run(['indexers' => ['catalogsearch_fulltext', 'catalogrule_product']], $this->context());

        $this->assertSame(self::QUEUED, $result['reindexed']['catalogrule_product']);
        $this->assertMatchesRegularExpression('/^\d+(\.\d)? s$/', $result['reindexed']['catalogsearch_fulltext']);
    }

    #[DataProvider('ruleScopes')]
    public function testAdminhtmlAndCrontabScopesRebuildTheRuleIndexesInline(string $scope): void
    {
        $this->indexer('catalogrule_rule', 'once', 'never');
        $this->indexer('catalogrule_product', 'once', 'never');
        $this->indexer('catalog_product_price', 'once', 'never');

        $result = $this->tool($scope)->run(
            ['indexers' => ['catalogrule_rule', 'catalogrule_product', 'catalog_product_price']],
            $this->context()
        );

        $this->assertSame(['catalogrule_rule', 'catalogrule_product', 'catalog_product_price'], array_keys($result['reindexed']));
        foreach ($result['reindexed'] as $took) {
            $this->assertMatchesRegularExpression('/^\d+(\.\d)? s$/', $took);
        }
    }

    public static function ruleScopes(): array
    {
        return ['admin chat' => ['adminhtml'], 'cron' => ['crontab']];
    }

    public function testInvalidIndexesByDefaultInFrontendScope(): void
    {
        $this->indexer('catalogrule_rule', 'never', 'once', true);
        $this->indexer('catalogsearch_fulltext', 'once', 'never', true);
        $this->indexer('catalog_product_price', 'never', 'never');

        $result = $this->tool('frontend')->run([], $this->context());

        $this->assertSame(['catalogrule_rule', 'catalogsearch_fulltext'], array_keys($result['reindexed']));
        $this->assertSame(self::QUEUED, $result['reindexed']['catalogrule_rule']);
    }

    private function indexer(string $id, string $reindexAll, string $invalidate, bool $invalid = false): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getId')->willReturn($id);
        $indexer->method('getTitle')->willReturn($id);
        $indexer->method('isInvalid')->willReturn($invalid);
        $indexer->expects($this->$reindexAll())->method('reindexAll');
        $indexer->expects($this->$invalidate())->method('invalidate');
        $this->byId[$id] = $indexer;
    }

    private function tool(string $scope): Reindex
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn(array_values($this->byId));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturnCallback(fn (string $id) => $this->byId[$id]);
        $configScope = $this->createStub(ScopeInterface::class);
        $configScope->method('getCurrentScope')->willReturn($scope);

        return new Reindex($factory, $registry, $configScope);
    }

    private function context(): Context
    {
        return new Context(10, 5, $this->createStub(Conversations::class), 7);
    }
}
