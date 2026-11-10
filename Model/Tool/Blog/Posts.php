<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Blog;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\ObjectManagerInterface;
use Zero\Blog\Model\Admin\Entities;
use Zero\Blog\Model\Post;
use Zero\Blog\Model\Url;
use Zero\Cms\Model\Content\Repository;

/**
 * Blog posts for the tools: loading, a post as Claude reads it, its body
 * (Zero CMS content: the Article component's Markdown, or the other
 * components summarized).
 */
class Posts
{
    public function __construct(
        private readonly Repository $content,
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

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function url(): Url
    {
        return $this->objectManager->get(Url::class);
    }

    /**
     * @throws LocalizedException
     */
    public function load(int $id): AbstractModel
    {
        $post = $this->entities()->load(Entities::POST, $id);
        if (!$post->getId()) {
            throw new LocalizedException(__('No blog post #%1.', $id));
        }

        return $post;
    }

    public function row(AbstractModel $post): array
    {
        return [
            'id' => (int) $post->getId(),
            'title' => (string) $post->getData('title'),
            'url_key' => (string) $post->getData('url_key'),
            'active' => (bool) $post->getData('is_active'),
            'published_at' => $post->getData('published_at'),
            'author_id' => $post->getData('author_id') ? (int) $post->getData('author_id') : null,
            'category_ids' => array_map('intval', (array) $post->getCategoryIds()),
            'url' => $post->isVisible() ? $this->url()->post($post) : null,
        ];
    }

    /**
     * The post's Zero CMS content as text: an Article component's Markdown
     * as it is; other components as "type: their texts".
     */
    public function body(int $postId): string
    {
        $content = $this->content->getForStore(Post::CONTENT_TYPE, $postId, 0);
        $parts = [];
        foreach ((array) ($content['components'] ?? []) as $component) {
            $data = (array) ($component['data'] ?? []);
            if (($component['type'] ?? '') === 'article') {
                $parts[] = (string) ($data['body'] ?? '');
                continue;
            }
            $texts = array_filter(array_map(static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '', $data));
            $parts[] = '[' . ($component['type'] ?? '?') . ($texts ? ': ' . implode(' | ', $texts) : '') . ']';
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * The body replaced by one Article component with this Markdown.
     */
    public function writeBody(int $postId, string $markdown, string $user): void
    {
        $this->content->save(Post::CONTENT_TYPE, $postId, 0, ['components' => [['type' => 'article', 'data' => ['body' => $markdown]]]], $user, 'AI Assistant');
    }
}
