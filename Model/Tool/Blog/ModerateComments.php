<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Blog;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\ToolInterface;
use Zero\Blog\Model\Admin\Entities;

/**
 * moderate_comments: approve or reject blog comments, as the comments
 * grid's mass action does.
 */
class ModerateComments implements ToolInterface
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
        return 'moderate_comments';
    }

    public function description(): string
    {
        return 'Approve, reject or set back to pending the blog comments with these ids (from list_comments). Approved ones show under the post. Shown to the admin to confirm first.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'comment_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'status' => ['type' => 'string', 'enum' => ['approved', 'rejected', 'pending']],
        ], 'required' => ['comment_ids', 'status']];
    }

    public function resource(): string
    {
        return 'Zero_Blog::comments';
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
        [$status, $comments] = $this->parse($input);
        $lines = [];
        foreach ($comments as $comment) {
            $lines['#' . $comment->getId() . ' ' . $comment->getData('author_name')] = mb_substr(trim(strip_tags((string) $comment->getData('content'))), 0, 120);
        }

        return ['title' => (string) __('%1 %2 comment(s)', ucfirst($status === 'pending' ? 'reset' : rtrim($status, 'd')), count($comments)), 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        [$status, $comments] = $this->parse($input);
        $resource = $this->entities()->resource(Entities::COMMENT);
        $ids = [];
        foreach ($comments as $comment) {
            $comment->setData('status', ListComments::STATUSES[$status]);
            $resource->save($comment);
            $ids[] = (int) $comment->getId();
        }

        return ['status' => $status, 'comment_ids' => $ids];
    }

    /**
     * @return array{0: string, 1: \Magento\Framework\Model\AbstractModel[]}
     * @throws LocalizedException
     */
    private function parse(array $input): array
    {
        $status = (string) ($input['status'] ?? '');
        if (!isset(ListComments::STATUSES[$status])) {
            throw new LocalizedException(__('status must be approved, rejected or pending.'));
        }
        $comments = [];
        foreach (array_unique(array_map('intval', (array) ($input['comment_ids'] ?? []))) as $id) {
            $comment = $this->entities()->load(Entities::COMMENT, $id);
            if (!$comment->getId()) {
                throw new LocalizedException(__('No comment #%1.', $id));
            }
            $comments[] = $comment;
        }
        if (!$comments) {
            throw new LocalizedException(__('No comment ids given.'));
        }

        return [$status, $comments];
    }
}
