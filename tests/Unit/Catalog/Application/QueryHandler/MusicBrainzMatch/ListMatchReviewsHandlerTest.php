<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Catalog\Application\Query\MusicBrainzMatch\ListMatchReviewsQuery;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReview;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReviewPage;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchReviewReadInterface;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\ListMatchReviewsHandler;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Shared\Application\Exception\InvalidInputException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListMatchReviewsHandlerTest extends TestCase
{
    public function testItReturnsThePageTheCursorContinues(): void
    {
        $page = new MatchReviewPage(
            items: [new MatchReview(
                albumPublicId: 'reviewedAlbumPublicId',
                title: 'F-1 Trillion: Long Bed',
                reason: AlbumMatchReviewReason::TrackCountDiffers,
                checkedAt: new \DateTimeImmutable('2026-11-14 12:36:07 UTC'),
                bestScore: 84,
            )],
            nextCursor: 'the-next-cursor',
            hasNextPage: true,
            perPage: 1,
        );
        $reviews = $this->createMock(MatchReviewReadInterface::class);
        $reviews->expects(self::once())->method('page')->with('the-cursor', 1)->willReturn($page);

        self::assertSame($page, new ListMatchReviewsHandler($reviews)(new ListMatchReviewsQuery(limit: 1, cursor: 'the-cursor')));
    }

    /** @return iterable<string, array{int}> */
    public static function limitsOutOfRange(): iterable
    {
        yield 'zero' => [0];
        yield 'above the maximum' => [ListMatchReviewsQuery::MAX_LIMIT + 1];
    }

    #[DataProvider('limitsOutOfRange')]
    public function testALimitOutOfRangeIsRejectedBeforeAnyRead(int $limit): void
    {
        $reviews = $this->createMock(MatchReviewReadInterface::class);
        $reviews->expects(self::never())->method('page');

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage(sprintf('limit must be between 1 and %d.', ListMatchReviewsQuery::MAX_LIMIT));

        new ListMatchReviewsHandler($reviews)(new ListMatchReviewsQuery(limit: $limit));
    }
}
