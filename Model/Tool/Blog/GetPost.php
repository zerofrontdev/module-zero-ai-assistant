<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Blog;

use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ReadTool;

/**
 * get_post: one blog post in full, its body as Markdown.
 */
class GetPost extends ReadTool
{
    public function __construct(private readonly Posts $posts)
    {
    }

    public function name(): string
    {
        return 'get_post';
    }

    public function description(): string
    {
        return 'One blog post by id: every field, its tags and products, and the body as Markdown (an Article component; other components are summarized in [brackets]).';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];
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
        $post = $this->posts->load((int) ($input['id'] ?? 0));

        return $this->posts->row($post) + [
            'excerpt' => (string) $post->getData('excerpt'),
            'image' => $post->getData('image'),
            'image_alt' => $post->getData('image_alt'),
            'allow_comments' => (bool) $post->getData('allow_comments'),
            'meta_title' => $post->getData('meta_title'),
            'meta_description' => $post->getData('meta_description'),
            'tag_ids' => array_map('intval', (array) $post->getTagIds()),
            'product_ids' => array_map('intval', (array) $post->getProductIds()),
            'store_ids' => array_map('intval', (array) $post->getStoreIds()),
            'body' => $this->posts->body((int) $post->getId()),
        ];
    }
}
