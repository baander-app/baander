<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Application\QueryHandler;

use App\Recommendation\Application\Query\GetRecommendationQuery;
use App\Recommendation\Application\QueryHandler\GetRecommendationHandler;
use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class GetRecommendationHandlerTest extends TestCase
{
    public function testReturnsTheStoredRecommendation(): void
    {
        $recommendation = Recommendation::create('song', 'source', 'song', 'target', 0.8);
        $repository = $this->createMock(RecommendationRepositoryInterface::class);
        $repository->expects($this->once())->method('findByUuid')
            ->with($recommendation->getId())->willReturn($recommendation);

        self::assertSame($recommendation, (new GetRecommendationHandler($repository))(
            new GetRecommendationQuery($recommendation->getId()),
        ));
    }

    public function testReturnsNullForMissingRecommendation(): void
    {
        $repository = $this->createStub(RecommendationRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn(null);

        self::assertNull((new GetRecommendationHandler($repository))(new GetRecommendationQuery(Uuid::generate())));
    }
}
