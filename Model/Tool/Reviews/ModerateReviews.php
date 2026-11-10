<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Model\Tool\Reviews;

use Magento\Framework\Exception\LocalizedException;
use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Magento\Review\Model\ReviewFactory;
use Magento\Review\Model\Review;
use Zero\AiAssistant\Model\Tool\Context;
use Zero\AiAssistant\Model\Tool\InputAclInterface;
use Zero\AiAssistant\Model\Tool\ToolInterface;

/**
 * moderate_reviews: approve or refuse reviews, as the reviews grid does;
 * the products' rating summaries are rebuilt. Like the grid's mass status
 * change, a role with Pending Reviews only may moderate pending reviews
 * only; any other review needs All Reviews (resourceFor()).
 */
class ModerateReviews implements ToolInterface, InputAclInterface
{
    public function __construct(
        private readonly ReviewFactory $reviewFactory,
        private readonly ReviewResource $reviewResource
    ) {
    }

    public function name(): string
    {
        return 'moderate_reviews';
    }

    public function description(): string
    {
        return 'Approve (shown on the product), refuse (not_approved) or set back to pending the reviews with these ids (from list_reviews). Shown to the admin to confirm first.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'review_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'status' => ['type' => 'string', 'enum' => ['approved', 'not_approved', 'pending']],
        ], 'required' => ['review_ids', 'status']];
    }

    public function resource(): string
    {
        return 'Magento_Review::pending';
    }

    /**
     * Pending Reviews when every review given is pending, else All Reviews.
     *
     * @throws LocalizedException
     */
    public function resourceFor(array $input): string
    {
        [, $reviews] = $this->parse($input);
        foreach ($reviews as $review) {
            if ((int) $review->getData('status_id') !== Review::STATUS_PENDING) {
                return 'Magento_Review::reviews_all';
            }
        }

        return 'Magento_Review::pending';
    }

    public function area(): string
    {
        return 'reviews';
    }

    public function changes(): bool
    {
        return true;
    }

    public function preview(array $input, Context $context): array
    {
        [$status, $reviews] = $this->parse($input);
        $lines = [];
        foreach ($reviews as $review) {
            $lines['#' . $review->getId() . ' ' . $review->getNickname()] = mb_substr(trim((string) $review->getTitle()) . ': ' . trim(strip_tags((string) $review->getDetail())), 0, 140);
        }

        return ['title' => (string) __('%1: %2 review(s)', ucfirst(str_replace('_', ' ', $status)), count($reviews)), 'lines' => $lines];
    }

    public function run(array $input, Context $context): array
    {
        [$status, $reviews] = $this->parse($input);
        $ids = [];
        foreach ($reviews as $review) {
            $review->setStatusId(ListReviews::STATUSES[$status]);
            $this->reviewResource->save($review);
            $review->aggregate();
            $ids[] = (int) $review->getId();
        }

        return ['status' => $status, 'review_ids' => $ids];
    }

    /**
     * @return array{0: string, 1: \Magento\Review\Model\Review[]}
     * @throws LocalizedException
     */
    private function parse(array $input): array
    {
        $status = (string) ($input['status'] ?? '');
        if (!isset(ListReviews::STATUSES[$status])) {
            throw new LocalizedException(__('status must be approved, not_approved or pending.'));
        }
        $reviews = [];
        foreach (array_unique(array_map('intval', (array) ($input['review_ids'] ?? []))) as $id) {
            $review = $this->reviewFactory->create();
            $this->reviewResource->load($review, $id);
            if (!$review->getId()) {
                throw new LocalizedException(__('No review #%1.', $id));
            }
            $reviews[] = $review;
        }
        if (!$reviews) {
            throw new LocalizedException(__('No review ids given.'));
        }

        return [$status, $reviews];
    }
}
