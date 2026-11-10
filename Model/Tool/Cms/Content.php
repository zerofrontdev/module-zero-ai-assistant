<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Cms;

use Zero\AiAssistant\Model\ModelHtml;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Model\PageFactory;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory as BlockCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * CMS pages and blocks, alike for the tools: type "page" or "block".
 */
class Content
{
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly PageFactory $pageFactory,
        private readonly BlockFactory $blockFactory,
        private readonly PageCollectionFactory $pageCollectionFactory,
        private readonly BlockCollectionFactory $blockCollectionFactory,
        private readonly ModelHtml $html
    ) {
    }

    public function search(string $type, string $query, int $limit): array
    {
        $collection = $type === 'page' ? $this->pageCollectionFactory->create() : $this->blockCollectionFactory->create();
        if ($query !== '') {
            $collection->addFieldToFilter(['title', 'identifier'], [['like' => '%' . $query . '%'], ['like' => '%' . $query . '%']]);
        }
        $collection->setOrder('update_time', 'DESC')->setPageSize($limit);
        $items = [];
        foreach ($collection as $item) {
            $items[] = [
                'id' => (int) $item->getId(),
                'title' => $item->getTitle(),
                'identifier' => $item->getIdentifier(),
                'active' => (bool) $item->getIsActive(),
                'updated' => $item->getUpdateTime(),
            ];
        }

        return $items;
    }

    /**
     * @throws LocalizedException
     */
    public function get(string $type, int $id): \Magento\Framework\Model\AbstractModel
    {
        try {
            return $type === 'page' ? $this->pageRepository->getById($id) : $this->blockRepository->getById($id);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No CMS %1 #%2.', $type, $id));
        }
    }

    public function save(string $type, array $input): \Magento\Framework\Model\AbstractModel
    {
        $item = !empty($input['id']) ? $this->get($type, (int) $input['id'])
            : ($type === 'page' ? $this->pageFactory->create() : $this->blockFactory->create());
        if (empty($input['id'])) {
            $item->setData('stores', [0])->setIsActive(true);
        }
        $fields = ['title', 'identifier', 'content', 'active'];
        if ($type === 'page') {
            $fields = [...$fields, 'content_heading', 'meta_title', 'meta_description'];
        }
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field];
                if (is_string($value)) {
                    $old = (string) $item->getData($field);
                    $value = $field === 'content' ? $this->html->clean($value, $old) : $this->html->text($value, $old);
                }
                $item->setData($field === 'active' ? 'is_active' : $field, $value);
            }
        }

        return $type === 'page' ? $this->pageRepository->save($item) : $this->blockRepository->save($item);
    }
}
