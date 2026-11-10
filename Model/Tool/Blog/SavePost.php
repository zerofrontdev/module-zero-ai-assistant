<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Blog;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\AiAssistant\Model\Uploads;
use Zero\Blog\Model\Admin\Entities;
use Zero\Blog\Model\Admin\Products;
use Zero\Blog\Model\Admin\Tags;
use Zero\Blog\Model\ResourceModel\Post as PostResource;
use Zero\Blog\Model\UrlKey;

/**
 * save_post: a blog post created or changed, as the admin's post form
 * saves one; the body is Markdown and becomes the post's Article component.
 */
class SavePost implements ToolInterface
{
    private const IMAGE_DIR = 'zero_blog';

    public function __construct(
        private readonly Posts $posts,
        private readonly TimezoneInterface $timezone,
        private readonly Uploads $uploads,
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
    private function postResource(): PostResource
    {
        return $this->objectManager->get(PostResource::class);
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function urlKey(): UrlKey
    {
        return $this->objectManager->get(UrlKey::class);
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function tags(): Tags
    {
        return $this->objectManager->get(Tags::class);
    }

    /**
     * Made when the tool runs: the class belongs to a module that may be absent.
     */
    private function products(): Products
    {
        return $this->objectManager->get(Products::class);
    }

    public function name(): string
    {
        return 'save_post';
    }

    public function description(): string
    {
        return 'Create a blog post (no id: title and body required) or change one (id; only the fields given change). body is Markdown (## headings, lists, **bold**, links) and replaces the post\'s whole content. New posts are drafts unless active is true; published_at in the store\'s time. An uploaded photo becomes the cover with image_upload_id. Shown to the admin to confirm first. Use list_blog_categories for category and author ids.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'body' => ['type' => 'string', 'description' => 'Markdown'],
            'excerpt' => ['type' => 'string', 'description' => '1–2 sentences for the list page and the share card'],
            'url_key' => ['type' => 'string'],
            'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'tags' => ['type' => 'string', 'description' => 'Comma-separated; new ones are created'],
            'product_skus' => ['type' => 'string', 'description' => 'Comma-separated SKUs shown with the post'],
            'author_id' => ['type' => 'integer'],
            'active' => ['type' => 'boolean'],
            'published_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM, store time'],
            'allow_comments' => ['type' => 'boolean'],
            'image_upload_id' => ['type' => 'integer', 'description' => 'An uploaded photo (upload #N) as the cover'],
            'image_alt' => ['type' => 'string'],
            'meta_title' => ['type' => 'string'],
            'meta_description' => ['type' => 'string'],
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

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        $new = empty($input['id']);
        if ($new) {
            if (trim((string) ($input['title'] ?? '')) === '' || trim((string) ($input['body'] ?? '')) === '') {
                throw new LocalizedException(__('A new post needs a title and a body.'));
            }
            $title = (string) __('Create blog post "%1"', $input['title']);
        } else {
            $post = $this->posts->load((int) $input['id']);
            $title = (string) __('Change blog post "%1" (#%2)', $post->getData('title'), $post->getId());
        }
        if ($new || isset($input['url_key']) || isset($input['title'])) {
            $this->urlKeyFor($input + ['title' => $input['title'] ?? (isset($post) ? (string) $post->getData('title') : '')], (int) ($input['id'] ?? 0));
        }
        if (array_key_exists('published_at', $input) && !$this->validDate((string) $input['published_at'])) {
            throw new LocalizedException(__('Type published_at as YYYY-MM-DD HH:MM.'));
        }
        $lines = [];
        foreach ($input as $field => $value) {
            if ($field === 'id') {
                continue;
            }
            if ($field === 'image_upload_id') {
                $lines['cover'] = '#' . $context->upload((int) $value)['upload_id'];
            } elseif ($field === 'body') {
                $lines['body'] = str_word_count(strip_tags((string) $value)) . ' words: ' . mb_substr(trim(strip_tags((string) $value)), 0, 160) . '…';
            } elseif (is_array($value)) {
                $lines[$field] = implode(', ', $value);
            } else {
                $lines[$field] = is_bool($value) ? ($value ? 'yes' : 'no') : mb_substr(trim((string) $value), 0, 200);
            }
        }
        if ($new && !array_key_exists('active', $input)) {
            $lines['active'] = 'no (draft)';
        }

        return ['title' => $title, 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        $new = empty($input['id']);
        $post = $new ? $this->entities()->create(Entities::POST) : $this->posts->load((int) $input['id']);
        $data = [];
        if (isset($input['title'])) {
            $data['title'] = mb_substr(trim((string) $input['title']), 0, 255);
        }
        if ($new || isset($input['url_key'])) {
            $data['url_key'] = $this->urlKeyFor($input + ['title' => $data['title'] ?? $post->getData('title')], (int) $post->getId());
        }
        foreach (['excerpt' => 1000, 'image_alt' => 255, 'meta_title' => 255, 'meta_description' => 500] as $field => $max) {
            if (array_key_exists($field, $input)) {
                $value = mb_substr(trim((string) $input[$field]), 0, $max);
                $data[$field] = $value !== '' ? $value : null;
            }
        }
        if (array_key_exists('author_id', $input)) {
            $data['author_id'] = (int) $input['author_id'] ?: null;
        }
        if (array_key_exists('active', $input) || $new) {
            $data['is_active'] = (int) (bool) ($input['active'] ?? false);
        }
        if (array_key_exists('allow_comments', $input) || $new) {
            $data['allow_comments'] = (int) (bool) ($input['allow_comments'] ?? true);
        }
        if (array_key_exists('published_at', $input) && trim((string) $input['published_at']) !== '') {
            $data['published_at'] = $this->timezone->convertConfigTimeToUtc(trim((string) $input['published_at']));
        } elseif (($data['is_active'] ?? 0) && !$post->getData('published_at')) {
            $data['published_at'] = gmdate('Y-m-d H:i:s');
        }
        if (array_key_exists('category_ids', $input)) {
            $data['category_ids'] = array_map('intval', (array) $input['category_ids']);
        }
        if (array_key_exists('tags', $input)) {
            $data['tag_ids'] = $this->tags()->idsFor((string) $input['tags']);
        }
        $unknown = [];
        if (array_key_exists('product_skus', $input)) {
            $found = $this->products()->idsFor((string) $input['product_skus']);
            $data['product_ids'] = $found['ids'];
            $unknown = $found['unknown'];
        }
        if (!empty($input['image_upload_id'])) {
            $data['image'] = '/' . $this->uploads->publish($context->upload((int) $input['image_upload_id']), self::IMAGE_DIR);
        }
        if ($new) {
            $data['store_ids'] = [0];
        }
        $post->addData($data);
        $this->entities()->resource(Entities::POST)->save($post);
        if (isset($input['body'])) {
            $this->posts->writeBody((int) $post->getId(), (string) $input['body'], 'AI Assistant (admin #' . $context->userId . ')');
        }
        $post = $this->posts->load((int) $post->getId());

        return $this->posts->row($post) + ($unknown ? ['unknown_skus' => $unknown] : []);
    }

    /**
     * @throws LocalizedException
     */
    private function urlKeyFor(array $input, int $exceptId): string
    {
        $key = trim(strtolower((string) ($input['url_key'] ?? ''))) ?: $this->urlKey()->fromText((string) ($input['title'] ?? ''));
        if (!$this->urlKey()->isValid($key)) {
            throw new LocalizedException(__('The URL key can have lowercase letters, digits and dashes only.'));
        }
        if (in_array($key, UrlKey::RESERVED, true)) {
            throw new LocalizedException(__('The URL key "%1" is used by the blog itself.', $key));
        }
        if ($this->postResource()->urlKeyTakenBy($key, [0], $exceptId) !== null) {
            throw new LocalizedException(__('Another post already uses the URL key "%1".', $key));
        }

        return $key;
    }

    private function validDate(string $value): bool
    {
        return $value === '' || preg_match('/^\d{4}-\d{2}-\d{2}( \d{1,2}:\d{2})?$/', $value) === 1;
    }
}
