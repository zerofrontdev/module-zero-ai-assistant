<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Catalog;

use Zero\AiAssistant\Model\ModelHtml;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as ConfigurableOptionsFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute as AttributeResource;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filter\TranslitUrl;
use Magento\Store\Model\StoreManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Uploads;

/**
 * Products as the assistant creates and changes them: all websites, the
 * default attribute set, prices in the base currency, stock managed, images
 * from the conversation's uploads (the first one the main image). Variants:
 * a configurable product over one dropdown attribute (size, color…), a
 * hidden simple product per option, options added where missing.
 */
class ProductWriter
{
    private const TEXTS = ['name', 'description', 'short_description', 'meta_title', 'meta_description', 'url_key'];

    public function __construct(
        private readonly ProductFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductResource $productResource,
        private readonly ConfigurableOptionsFactory $configurableOptionsFactory,
        private readonly EavConfig $eavConfig,
        private readonly AttributeResource $attributeResource,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly TranslitUrl $translitUrl,
        private readonly Uploads $uploads,
        private readonly BackendUrl $backendUrl,
        private readonly ModelHtml $html
    ) {
    }

    public function exists(string $sku): bool
    {
        return (bool) $this->productResource->getIdBySku($sku);
    }

    /**
     * @throws LocalizedException
     */
    public function get(string $sku): ProductInterface
    {
        try {
            return $this->productRepository->get($sku, true, 0, true);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product with SKU %1.', $sku));
        }
    }

    public function sku(array $input): string
    {
        $sku = trim((string) ($input['sku'] ?? ''));

        return $sku !== '' ? $sku : strtoupper(substr($this->urlKey((string) $input['name']), 0, 40)) . '-' . strtoupper(bin2hex(random_bytes(2)));
    }

    /**
     * @throws LocalizedException
     */
    public function variantAttribute(string $code): \Magento\Eav\Model\Entity\Attribute\AbstractAttribute
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        if (!$attribute->getId() || $attribute->getFrontendInput() !== 'select' || !$attribute->getIsGlobal()) {
            throw new LocalizedException(__('"%1" can\'t vary a product: it must be a global dropdown attribute (as size or color).', $code));
        }

        return $attribute;
    }

    /**
     * @throws LocalizedException
     */
    public function create(array $input, Context $context): ProductInterface
    {
        $sku = $this->sku($input);
        if ($this->exists($sku)) {
            throw new LocalizedException(__('A product with SKU %1 already exists.', $sku));
        }
        $this->storeManager->setCurrentStore(0);
        $variants = $input['variants'] ?? null;
        if ($variants) {
            $attribute = $this->variantAttribute((string) $variants['attribute_code']);
            $setId = (int) $this->productResource->getEntityType()->getDefaultAttributeSetId();
            $this->eavSetupFactory->create()->addAttributeToGroup(Product::ENTITY, $setId, 'General', $attribute->getAttributeCode());
            $optionIds = $this->optionIds($attribute->getAttributeCode(), array_column($variants['options'], 'label'));
            $childIds = [];
            foreach ($variants['options'] as $option) {
                $label = (string) $option['label'];
                $child = $this->newProduct([
                    'sku' => $sku . '-' . strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $this->urlKey($label)) ?: (string) $optionIds[$label]),
                    'name' => $input['name'] . ' — ' . $label,
                    'url_key' => $this->urlKey($input['url_key'] ?? $input['name']) . '-' . $this->urlKey($label),
                    'price' => (float) ($option['price'] ?? $input['price']),
                    'qty' => (float) ($option['qty'] ?? $input['qty'] ?? 0),
                    'visibility' => Visibility::VISIBILITY_NOT_VISIBLE,
                    'category_ids' => [],
                ] + $input, 'simple');
                $child->setData($attribute->getAttributeCode(), $optionIds[$label]);
                $childIds[] = (int) $this->productRepository->save($child)->getId();
            }
            $parent = $this->newProduct(['sku' => $sku] + $input, 'configurable');
            $this->images($parent, (array) ($input['image_upload_ids'] ?? []), $context);
            $parent = $this->productRepository->save($parent);
            $parent = $this->productRepository->getById((int) $parent->getId(), true, 0, true);
            $extension = $parent->getExtensionAttributes();
            $extension->setConfigurableProductOptions($this->configurableOptionsFactory->create([[
                'attribute_id' => $attribute->getId(),
                'code' => $attribute->getAttributeCode(),
                'label' => $attribute->getDefaultFrontendLabel(),
                'position' => 0,
                'values' => array_map(static fn ($id) => ['value_index' => $id], array_values($optionIds)),
            ]]));
            $extension->setConfigurableProductLinks($childIds);
            $parent->setExtensionAttributes($extension);

            return $this->productRepository->save($parent);
        }
        $product = $this->newProduct(['sku' => $sku] + $input, 'simple');
        $this->images($product, (array) ($input['image_upload_ids'] ?? []), $context);

        return $this->productRepository->save($product);
    }

    /**
     * @throws LocalizedException
     */
    public function update(array $input, Context $context): ProductInterface
    {
        $this->storeManager->setCurrentStore(0);
        $product = $this->get((string) $input['sku']);
        foreach (self::TEXTS as $field) {
            if (array_key_exists($field, $input)) {
                $old = (string) $product->getData($field);
                $product->setData($field, match ($field) {
                    'url_key' => $this->urlKey((string) $input[$field]),
                    'description', 'short_description' => $this->html->clean((string) $input[$field], $old),
                    default => $this->html->text((string) $input[$field], $old),
                });
            }
        }
        $this->applyValues($product, $input);
        $this->images($product, (array) ($input['add_image_upload_ids'] ?? []), $context, (int) count((array) $product->getMediaGalleryEntries()) === 0);

        return $this->productRepository->save($product);
    }

    /**
     * Values this store kept put back as they were: the texts verbatim (no model
     * sanitizer, no URL key rewrite: they are the product's own earlier values, with
     * their iframes, widgets and forms), prices, status, categories and qty as update()
     * sets them. Images and any other key are ignored. Only for what was read from the
     * product itself: zero_ai_action.undo (Agent::confirm, from UpdateProduct::snapshot())
     * and zero_ai_job_item.before (Bulk\Consumer::write). Never for the model's input.
     *
     * @param array<string, mixed> $saved sku and the kept values
     * @throws LocalizedException
     */
    public function restore(array $saved, Context $context): ProductInterface
    {
        $this->storeManager->setCurrentStore(0);
        $product = $this->get((string) $saved['sku']);
        foreach (self::TEXTS as $field) {
            if (array_key_exists($field, $saved)) {
                // An empty url_key: Magento makes it from the name on save.
                $product->setData($field, (string) $saved[$field]);
            }
        }
        $this->applyValues($product, $saved);

        return $this->productRepository->save($product);
    }

    /**
     * Price, special price, status, categories and qty (simple products) from the given keys.
     */
    private function applyValues(ProductInterface $product, array $input): void
    {
        if (array_key_exists('price', $input)) {
            $product->setPrice((float) $input['price']);
        }
        if (array_key_exists('special_price', $input)) {
            $product->setSpecialPrice($input['special_price'] === null ? null : (float) $input['special_price']);
        }
        if (array_key_exists('enabled', $input)) {
            $product->setStatus($input['enabled'] ? Status::STATUS_ENABLED : Status::STATUS_DISABLED);
        }
        if (array_key_exists('category_ids', $input)) {
            $product->setCategoryIds(array_map('intval', (array) $input['category_ids']));
        }
        if (array_key_exists('qty', $input) && $product->getTypeId() === 'simple') {
            $product->setStockData(['use_config_manage_stock' => 1, 'qty' => (float) $input['qty'], 'is_in_stock' => (float) $input['qty'] > 0 ? 1 : 0]);
        }
    }

    public function editUrl(ProductInterface $product): string
    {
        return $this->backendUrl->getUrl('catalog/product/edit', ['id' => $product->getId()]);
    }

    public function urlKey(string $text): string
    {
        return trim($this->translitUrl->filter($text), '-') ?: 'product';
    }

    private function newProduct(array $data, string $type): Product
    {
        $product = $this->productFactory->create();
        $product->setStoreId(0)
            ->setTypeId($type)
            ->setAttributeSetId((int) $this->productResource->getEntityType()->getDefaultAttributeSetId())
            ->setSku((string) $data['sku'])
            ->setName($this->html->text((string) $data['name']))
            ->setUrlKey($this->urlKey((string) ($data['url_key'] ?? $data['name'])))
            ->setPrice((float) $data['price'])
            ->setStatus(($data['enabled'] ?? true) ? Status::STATUS_ENABLED : Status::STATUS_DISABLED)
            ->setVisibility((int) ($data['visibility'] ?? Visibility::VISIBILITY_BOTH))
            ->setWebsiteIds(array_map(static fn ($w) => (int) $w->getId(), $this->storeManager->getWebsites()))
            ->setCategoryIds(array_map('intval', (array) ($data['category_ids'] ?? [])))
            ->setTaxClassId(2)
            ->setWeight((float) ($data['weight'] ?? 0.5))
            ->setDescription($this->html->clean((string) ($data['description'] ?? '')))
            ->setShortDescription($this->html->clean((string) ($data['short_description'] ?? '')))
            ->setMetaTitle($this->html->text((string) ($data['meta_title'] ?? $data['name'])))
            ->setMetaDescription($this->html->text((string) ($data['meta_description'] ?? '')));
        if (isset($data['special_price'])) {
            $product->setSpecialPrice((float) $data['special_price']);
        }
        $product->setStockData($type === 'simple'
            ? ['use_config_manage_stock' => 1, 'qty' => (float) ($data['qty'] ?? 0), 'is_in_stock' => (float) ($data['qty'] ?? 0) > 0 ? 1 : 0]
            : ['use_config_manage_stock' => 1, 'is_in_stock' => 1]);

        return $product;
    }

    /**
     * The uploads into the product's gallery; the first one main, small and thumbnail.
     */
    private function images(Product $product, array $uploadIds, Context $context, bool $firstIsMain = true): void
    {
        foreach (array_values($uploadIds) as $i => $uploadId) {
            $path = $this->uploads->copyToMedia($context->upload((int) $uploadId));
            $product->addImageToMediaGallery($path, $i === 0 && $firstIsMain ? ['image', 'small_image', 'thumbnail'] : [], true, false);
        }
    }

    /**
     * The attribute's option ids for these labels, the missing ones added.
     *
     * @return array<string, int>
     */
    private function optionIds(string $code, array $labels): array
    {
        $labels = array_values(array_unique(array_map('strval', $labels)));
        $existing = $this->options($code);
        $new = ['value' => [], 'order' => []];
        foreach (array_values(array_diff($labels, array_keys($existing))) as $i => $label) {
            $new['value']['option_' . $i] = [0 => $label];
            $new['order']['option_' . $i] = 1000 + $i;
        }
        if ($new['value']) {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            $attribute->setData('option', $new);
            $this->attributeResource->save($attribute);
            $this->eavConfig->clear();
        }

        return array_intersect_key($this->options($code), array_flip($labels));
    }

    private function options(string $code): array
    {
        $options = [];
        foreach ($this->eavConfig->getAttribute(Product::ENTITY, $code)->setStoreId(0)->getSource()->getAllOptions(false) as $option) {
            $options[(string) $option['label']] = (int) $option['value'];
        }

        return $options;
    }
}
