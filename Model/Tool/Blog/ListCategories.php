<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Blog;

use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;
use Zero\Blog\Model\Admin\Entities;

/**
 * list_blog_categories: the blog's categories and authors, for save_post.
 */
class ListCategories extends ReadTool
{
    public function __construct(private readonly ObjectManagerInterface $objectManager)
    {
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function entities(): Entities
    {
        return $this->objectManager->get(Entities::class);
    }

    public function name(): string
    {
        return 'list_blog_categories';
    }

    public function description(): string
    {
        return 'The blog\'s categories (id, title, active) and authors (id, name), for save_post\'s category_ids and author_id.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function resource(): string
    {
        return 'Zero_Blog::posts';
    }

    public function area(): string
    {
        return 'blog';
    }

    public function run(array $input, Context $context): array
    {
        $categories = [];
        foreach ($this->entities()->collection(Entities::CATEGORY)->setOrder('position', 'ASC') as $category) {
            $categories[] = ['id' => (int) $category->getId(), 'title' => (string) $category->getData('title'), 'active' => (bool) $category->getData('is_active')];
        }
        $authors = [];
        foreach ($this->entities()->collection(Entities::AUTHOR) as $author) {
            $authors[] = ['id' => (int) $author->getId(), 'name' => (string) $author->getData('name')];
        }

        return ['categories' => $categories, 'authors' => $authors];
    }
}
