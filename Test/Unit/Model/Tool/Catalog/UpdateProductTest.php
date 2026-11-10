<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Tool\Catalog;

use Magento\Catalog\Api\Data\ProductInterface;
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
