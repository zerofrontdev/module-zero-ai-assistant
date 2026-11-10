<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Tool\Catalog;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as ConfigurableOptionsFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute as AttributeResource;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Filter\TranslitUrl;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Conversations;
use Zero\AiAssistant\Model\ModelHtml;
use Zero\AiAssistant\Model\Tool\Catalog\ProductWriter;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Uploads;

/**
 * Undo puts back what the product itself held, byte for byte (its iframes, widgets and forms); what the model writes
 * still goes through the sanitizer.
 */
class ProductWriterTest extends TestCase
{
    private const ORIGINAL = '<p>Intro</p><iframe src="https://www.youtube.com/embed/x"></iframe>{{widget type="X"}}<table><tr><td>1</td></tr></table>';

    private ?Product $saved = null;

    private function product(): Product
    {
        // A real product model without its constructor: setData/getData as Magento has them.
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $product->setData([
            'sku' => 'MH01',
            'type_id' => 'simple',
            'name' => 'AI name',
            'description' => '<p>AI written</p>',
            'short_description' => '<p>AI short</p>',
            'url_key' => 'ai-key',
            'price' => 99.0,
            'special_price' => 80.0,
            'status' => Status::STATUS_ENABLED,
        ]);
        return $product;
    }

    private function writer(TranslitUrl $translit, Uploads $uploads): ProductWriter
    {
        $product = $this->product();
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(static fn (string $sku) => $sku === 'MH01' ? $product : null);
        $repository->method('save')->willReturnCallback(function (Product $p) {
            $this->saved = $p;
            return $p;
        });

        return new ProductWriter(
            $this->createStub(ProductFactory::class),
            $repository,
            $this->createStub(ProductResource::class),
            $this->createStub(ConfigurableOptionsFactory::class),
            $this->createStub(EavConfig::class),
            $this->createStub(AttributeResource::class),
            $this->createStub(EavSetupFactory::class),
            $this->createStub(StoreManagerInterface::class),
            $translit,
            $uploads,
            $this->createStub(BackendUrl::class),
            new ModelHtml()
        );
    }

    private function context(): Context
    {
        return new Context(10, 5, $this->createStub(Conversations::class), 7);
    }

    public function testRestorePutsTheSavedTextBackByteForByte(): void
    {
        $translit = $this->createMock(TranslitUrl::class);
        $translit->expects($this->never())->method('filter');
        $uploads = $this->createMock(Uploads::class);
        $uploads->expects($this->never())->method('copyToMedia');
        $short = '<form action="/subscribe"><input name="email"><button>Go</button></form><svg viewBox="0 0 1 1"></svg>';

        $this->writer($translit, $uploads)->restore([
            'sku' => 'MH01',
            'name' => 'Shirt {{var name}}',
            'description' => self::ORIGINAL,
            'short_description' => $short,
            'meta_title' => '{{config path="general/store_information/name"}} shirt',
            'meta_description' => '',
            'url_key' => 'Old Key_1',
            'price' => 45,
            'special_price' => null,
            'enabled' => false,
            'category_ids' => ['3', '4'],
            'qty' => 5,
            'add_image_upload_ids' => [1, 2],
            'image' => '/m/h/mh01.jpg',
            'color' => 'red',
        ], $this->context());

        $saved = $this->saved;
        $this->assertNotNull($saved, 'saved through the repository');
        $this->assertSame(self::ORIGINAL, $saved->getData('description'));
        $this->assertSame($short, $saved->getData('short_description'));
        $this->assertSame('Shirt {{var name}}', $saved->getData('name'));
        $this->assertSame('{{config path="general/store_information/name"}} shirt', $saved->getData('meta_title'));
        $this->assertSame('', $saved->getData('meta_description'));
        $this->assertSame('Old Key_1', $saved->getData('url_key'), 'not rewritten as a new URL key');
        // The values as update() sets them (applyValues).
        $this->assertSame(45.0, $saved->getData('price'));
        $this->assertNull($saved->getData('special_price'));
        $this->assertSame(Status::STATUS_DISABLED, $saved->getData('status'));
        $this->assertSame([3, 4], $saved->getData('category_ids'));
        $this->assertSame(['use_config_manage_stock' => 1, 'qty' => 5.0, 'is_in_stock' => 1], $saved->getData('stock_data'));
        // Images and keys outside the whitelist are ignored.
        $this->assertNull($saved->getData('image'));
        $this->assertNull($saved->getData('color'));
        $this->assertNull($saved->getData('add_image_upload_ids'));
    }

    public function testRestoreLeavesOutWhatWasNotKept(): void
    {
        $this->writer($this->createStub(TranslitUrl::class), $this->createStub(Uploads::class))
            ->restore(['sku' => 'MH01', 'description' => self::ORIGINAL], $this->context());

        $this->assertSame(self::ORIGINAL, $this->saved->getData('description'));
        $this->assertSame('AI name', $this->saved->getData('name'));
        $this->assertSame(99.0, $this->saved->getData('price'));
        $this->assertSame(80.0, $this->saved->getData('special_price'));
        $this->assertNull($this->saved->getData('stock_data'));
    }

    public function testAnUpdateFromTheModelIsStillCleaned(): void
    {
        $translit = $this->createMock(TranslitUrl::class);
        $translit->expects($this->once())->method('filter')->with('Old Key_1')->willReturn('old-key-1');

        $this->writer($translit, $this->createStub(Uploads::class))->update([
            'sku' => 'MH01',
            'description' => self::ORIGINAL,
            'url_key' => 'Old Key_1',
            'price' => 45,
        ], $this->context());

        $description = (string) $this->saved->getData('description');
        $this->assertStringContainsString('<p>Intro</p>', $description);
        $this->assertStringNotContainsString('<iframe', $description);
        $this->assertStringNotContainsString('{{widget', $description);
        $this->assertSame('old-key-1', $this->saved->getData('url_key'));
        $this->assertSame(45.0, $this->saved->getData('price'), 'the same values path as restore()');
    }
}
