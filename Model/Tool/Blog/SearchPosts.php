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
 * search_posts: blog posts by words in the title, drafts or published.
 */
class SearchPosts extends ReadTool
{
    public function __construct(
        private readonly Posts $posts,
        private readonly ObjectManagerInterface $objectManager
    ) {
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
        return 'search_posts';
    }

    public function description(): string
    {
        return 'Find blog posts by words in the title or URL key (empty query: the latest), optionally only drafts or only published ones. Returns id, title, url_key, active, published_at, author_id, category_ids, url. Use get_post for the text.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['all', 'published', 'draft'], 'default' => 'all'],
            'limit' => ['type' => 'integer', 'default' => 20],
        ]];
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
        $collection = $this->entities()->collection(Entities::POST);
        $query = trim((string) ($input['query'] ?? ''));
        if ($query !== '') {
            $collection->addFieldToFilter(['title', 'url_key'], [['like' => '%' . $query . '%'], ['like' => '%' . $query . '%']]);
        }
        $status = (string) ($input['status'] ?? 'all');
        if ($status === 'published' || $status === 'draft') {
            $collection->addFieldToFilter('is_active', $status === 'published' ? 1 : 0);
        }
        $collection->setOrder('post_id', 'DESC')->setPageSize(max(1, min(50, (int) ($input['limit'] ?? 20))));
        $rows = [];
        foreach ($collection as $post) {
            $rows[] = $this->posts->row($post);
        }

        return ['posts' => $rows];
    }
}
