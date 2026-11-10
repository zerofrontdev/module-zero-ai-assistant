<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Catalog;

use Zero\AiAssistant\Model\ModelHtml;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filter\TranslitUrl;
use Magento\Store\Model\StoreManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\AiAssistant\Model\Uploads;

class SaveCategory implements ToolInterface
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryResource $categoryResource,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly TranslitUrl $translitUrl,
        private readonly Uploads $uploads,
        private readonly ModelHtml $html
    ) {
    }

    public function name(): string
    {
        return 'save_category';
    }

    public function description(): string
    {
        return 'Create a category (no id: name and parent_id required) or change one (id; only the fields given change; parent_id moves it). An uploaded photo becomes its image with image_upload_id. Shown to the admin to confirm first. Use list_categories for ids.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'parent_id' => ['type' => 'integer'],
            'active' => ['type' => 'boolean'],
            'include_in_menu' => ['type' => 'boolean'],
            'description' => ['type' => 'string', 'description' => 'HTML'],
            'url_key' => ['type' => 'string'],
            'image_upload_id' => ['type' => 'integer', 'description' => 'An uploaded photo (upload #N) as the category image'],
        ]];
    }

    public function resource(): string
    {
        return 'Magento_Catalog::categories';
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
        if (!empty($input['parent_id'])) {
            $parent = $this->category((int) $input['parent_id']);
        }
        if (empty($input['id'])) {
            if (trim((string) ($input['name'] ?? '')) === '' || empty($input['parent_id'])) {
                throw new LocalizedException(__('A new category needs a name and a parent category.'));
            }
            $lines = [(string) __('Parent') => $parent->getName() . ' (#' . $parent->getId() . ')'];
            $lines[(string) __('Active')] = ($input['active'] ?? true) ? (string) __('Yes') : (string) __('No');
            if (!empty($input['image_upload_id'])) {
                $lines[(string) __('Image')] = '#' . $context->upload((int) $input['image_upload_id'])['upload_id'];
            }

            return ['title' => (string) __('Create category "%1"', $input['name']), 'lines' => $lines];
        }
        $category = $this->category((int) $input['id']);
        $lines = [];
        foreach (['name', 'active', 'include_in_menu', 'description', 'url_key'] as $field) {
            if (array_key_exists($field, $input)) {
                if ($field === 'description') {
                    $lines += $this->html->summary($field, $this->html->clean((string) $input[$field], (string) $category->getDescription()));
                    continue;
                }
                $lines[$field] = is_bool($input[$field]) ? ($input[$field] ? 'yes' : 'no') : mb_substr(strip_tags((string) $input[$field]), 0, 200);
            }
        }
        if (isset($parent)) {
            $lines['parent'] = $parent->getName() . ' (#' . $parent->getId() . ')';
        }
        if (!empty($input['image_upload_id'])) {
            $lines['image'] = '#' . $context->upload((int) $input['image_upload_id'])['upload_id'];
        }

        return ['title' => (string) __('Change category "%1" (#%2)', $category->getName(), $category->getId()), 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        $this->storeManager->setCurrentStore(0);
        if (empty($input['id'])) {
            return $this->create($input, $context);
        }
        $category = $this->category((int) $input['id']);
        foreach (['name' => 'setName', 'active' => 'setIsActive', 'include_in_menu' => 'setIncludeInMenu', 'description' => 'setDescription'] as $field => $setter) {
            if (array_key_exists($field, $input)) {
                $category->$setter(match ($field) {
                    'description' => $this->html->clean((string) $input[$field], (string) $category->getDescription()),
                    'name' => $this->html->text((string) $input[$field]),
                    default => $input[$field],
                });
            }
        }
        if (array_key_exists('url_key', $input)) {
            $category->setUrlKey($this->translitUrl->filter((string) $input['url_key']));
        }
        if (!empty($input['image_upload_id'])) {
            $category->setImage($this->image((int) $input['image_upload_id'], $context));
        }
        $category->setStoreId(0);
        $category = $this->categoryRepository->save($category);
        if (!empty($input['parent_id']) && (int) $input['parent_id'] !== (int) $category->getParentId()) {
            $category->move((int) $input['parent_id'], null);
        }

        return ['id' => (int) $category->getId(), 'name' => $category->getName()];
    }

    /**
     * A new category, saved as the admin saves one (the model with its parent's
     * path; Magento adds its own id to it), its tree position checked after:
     * a category whose path lacks its own id would take its parent's whole
     * subtree with it if it were ever deleted.
     *
     * @throws LocalizedException
     */
    private function create(array $input, Context $context): array
    {
        $parent = $this->category((int) $input['parent_id']);
        $parentPath = (string) $parent->getPath();
        if (!preg_match('#^1(/\d+)*$#', $parentPath) || !str_ends_with($parentPath, (string) $parent->getId())) {
            throw new LocalizedException(__('Category #%1 has a broken tree path (%2): fix it before adding to it.', $parent->getId(), $parentPath));
        }
        $category = $this->categoryFactory->create();
        $category->setStoreId(0)
            ->setAttributeSetId((int) $this->categoryResource->getEntityType()->getDefaultAttributeSetId())
            ->setParentId((int) $parent->getId())
            ->setPath($parentPath)
            ->setName($this->html->text((string) $input['name']))
            ->setIsActive($input['active'] ?? true)
            ->setIncludeInMenu($input['include_in_menu'] ?? true)
            ->setIsAnchor(true)
            ->setUrlKey($this->translitUrl->filter((string) ($input['url_key'] ?? $input['name'])))
            ->setDescription($this->html->clean((string) ($input['description'] ?? '')));
        if (!empty($input['image_upload_id'])) {
            $category->setImage($this->image((int) $input['image_upload_id'], $context));
        }
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $this->categoryResource->save($category);
            $id = (int) $category->getId();
            $path = (string) $connection->fetchOne($connection->select()
                ->from($this->resource->getTableName('catalog_category_entity'), ['path'])
                ->where('entity_id = ?', $id));
            if ($path !== $parentPath . '/' . $id) {
                throw new LocalizedException(__('The new category got a wrong tree path (%1); nothing was saved.', $path));
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e instanceof LocalizedException ? $e : new LocalizedException(__('Could not save the category: %1', $e->getMessage()));
        }

        return ['id' => $id, 'name' => $category->getName()];
    }

    /**
     * The upload as Magento's category image backend takes it: a file under
     * catalog/tmp/category, moved into catalog/category when the category saves.
     *
     * @throws LocalizedException
     */
    private function image(int $uploadId, Context $context): array
    {
        $path = $this->uploads->publish($context->upload($uploadId), 'catalog/tmp/category');

        return [['name' => basename($path), 'tmp_name' => $path]];
    }

    /**
     * @throws LocalizedException
     */
    private function category(int $id): \Magento\Catalog\Api\Data\CategoryInterface
    {
        try {
            return $this->categoryRepository->get($id, 0);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No category #%1.', $id));
        }
    }
}
