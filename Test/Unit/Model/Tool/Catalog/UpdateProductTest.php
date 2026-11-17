<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Tool\Catalog;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Catalog\Model\Product;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\ModelHtml;
use Zero\AiAssistant\Model\Tool\Catalog\ProductWriter;
use Zero\AiAssistant\Model\Tool\Catalog\UpdateProduct;
use Zero\AiAssistant\Model\Tool\Context;

/**
 * update_product's Undo writes the snapshot back through the verbatim writer, never through update() (the sanitizer).
 */
class UpdateProductTest extends TestCase
{
    /**
     * The proposal shows a price as the admin reads it, not as the database stores it.
     */
    public function testPreviewShowsNumbersWithoutTrailingZeros(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn('24-MB01');
        $product->method('getName')->willReturn('Joust Duffle Bag');
        $product->method('getData')->willReturnMap([
            ['price', null, '34.000000'],
            ['special_price', null, '29.500000'],
            ['name', null, 'Joust Duffle Bag'],
        ]);
        // The old quantity comes from the stock item: the product's own data has none.
        $stockItem = $this->createStub(StockItemInterface::class);
        $stockItem->method('getQty')->willReturn('99982.0000');
        // ProductExtensionInterface is generated code, so a plain object stands in for it.
        $product->method('getExtensionAttributes')->willReturn(new class ($stockItem) {
            public function __construct(private readonly StockItemInterface $stockItem)
            {
            }

            public function getStockItem(): StockItemInterface
            {
                return $this->stockItem;
            }
        });
        $writer = $this->createStub(ProductWriter::class);
        $writer->method('get')->willReturn($product);
        $context = new Context(10, 5, $this->createStub(Conversations::class), 7);

        $preview = (new UpdateProduct($writer, new ModelHtml()))->preview(
            ['sku' => '24-MB01', 'price' => 29, 'special_price' => '19.90', 'qty' => 50, 'name' => 'Version 2.0'],
            $context
        );

        $this->assertSame('34 → 29', $preview['lines']['price']);
        $this->assertSame('99982 → 50', $preview['lines']['qty']);
        $this->assertSame('29.5 → 19.9', $preview['lines']['special_price']);
        $this->assertSame('Joust Duffle Bag → Version 2.0', $preview['lines']['name']);
    }

    public function testRestoreUsesTheVerbatimWriter(): void
    {
        $snapshot = ['sku' => 'MH01', 'description' => '<iframe src="https://www.youtube.com/embed/x"></iframe>', 'price' => 45.0];
        $context = new Context(10, 5, $this->createStub(Conversations::class), 7);
        $product = $this->createStub(ProductInterface::class);
        $product->method('getId')->willReturn('12');
        $product->method('getSku')->willReturn('MH01');
        $writer = $this->createMock(ProductWriter::class);
        $writer->expects($this->once())->method('restore')->with($snapshot, $context)->willReturn($product);
        $writer->expects($this->never())->method('update');
        $writer->method('editUrl')->willReturn('https://store.example/admin/catalog/product/edit/id/12/');

        $result = (new UpdateProduct($writer, new ModelHtml()))->restore($snapshot, $context);

        $this->assertSame([
            'id' => 12,
            'sku' => 'MH01',
            'restored' => 'description, price',
            'edit_url' => 'https://store.example/admin/catalog/product/edit/id/12/',
        ], $result);
    }
}
