<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Tool\Reviews;

use Magento\Framework\Exception\LocalizedException;
use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Magento\Review\Model\Review;
use Magento\Review\Model\ReviewFactory;
use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Tool\InputAclInterface;
use Zero\AiAssistant\Model\Tool\Reviews\ModerateReviews;

/**
 * moderate_reviews needs what the stock grid's mass status change needs: Pending Reviews only while every review
 * given is pending, All Reviews for any other.
 */
class ModerateReviewsTest extends TestCase
{
    /**
     * @param array<int, int> $statuses review id => status_id (absent: no such review)
     */
    private function tool(array $statuses): ModerateReviews
    {
        $loaded = [];
        $factory = $this->createStub(ReviewFactory::class);
        $factory->method('create')->willReturnCallback(function () use (&$loaded) {
            // Each review knows its id and status once the resource "loads" it.
            $review = $this->createStub(Review::class);
            $index = count($loaded);
            $loaded[$index] = null;
            // Closures, not arrow functions: they must see what load() writes later.
            $review->method('getId')->willReturnCallback(static function () use (&$loaded, $index) {
                return $loaded[$index]['id'] ?? null;
            });
            $review->method('getData')->willReturnCallback(static function ($key = '') use (&$loaded, $index) {
                return $key === 'status_id' ? ($loaded[$index]['status_id'] ?? null) : null;
            });
            return $review;
        });
        $resource = $this->createStub(ReviewResource::class);
        $resource->method('load')->willReturnCallback(function ($review, $id) use (&$loaded, $statuses, $resource) {
            $index = count($loaded) - 1;
            if (isset($statuses[(int) $id])) {
                $loaded[$index] = ['id' => (string) $id, 'status_id' => (string) $statuses[(int) $id]];
            }
            return $resource;
        });

        return new ModerateReviews($factory, $resource);
    }

    public function testOnlyPendingReviewsNeedOnlyPending(): void
    {
        $tool = $this->tool([1 => Review::STATUS_PENDING, 2 => Review::STATUS_PENDING]);

        $this->assertInstanceOf(InputAclInterface::class, $tool);
        $this->assertSame('Magento_Review::pending', $tool->resourceFor(['review_ids' => [1, 2], 'status' => 'approved']));
    }

    public function testAnApprovedReviewNeedsAllReviews(): void
    {
        $tool = $this->tool([1 => Review::STATUS_PENDING, 2 => Review::STATUS_APPROVED]);

        $this->assertSame('Magento_Review::reviews_all', $tool->resourceFor(['review_ids' => [1, 2], 'status' => 'not_approved']));
    }

    public function testARefusedReviewNeedsAllReviewsToo(): void
    {
        $tool = $this->tool([3 => Review::STATUS_NOT_APPROVED]);

        $this->assertSame('Magento_Review::reviews_all', $tool->resourceFor(['review_ids' => [3], 'status' => 'pending']));
    }

    public function testTheToolStaysVisibleToAPendingOnlyRole(): void
    {
        $this->assertSame('Magento_Review::pending', $this->tool([])->resource());
    }

    public function testAnUnknownReviewIsRefusedAsParseDoes(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No review #9.');
        $this->tool([1 => Review::STATUS_PENDING])->resourceFor(['review_ids' => [1, 9], 'status' => 'approved']);
    }

    public function testABadStatusIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('status must be approved, not_approved or pending.');
        $this->tool([1 => Review::STATUS_PENDING])->resourceFor(['review_ids' => [1], 'status' => 'deleted']);
    }
}
