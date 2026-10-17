<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Application\QueryHandler;

use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Recommendation\Application\Query\GetPersonalizedRecommendationsQuery;
use App\Recommendation\Application\QueryHandler\GetPersonalizedRecommendationsHandler;
use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class GetPersonalizedRecommendationsHandlerTest extends TestCase
{
    public function testAggregatesAndEnrichesPersonalizedSongRecommendations(): void
    {
        $userId = Uuid::v7();
        $albumId = Uuid::v7();

        $song = Song::create(
            album: $albumId,
            title: 'Test Song',
            path: '/library/test-song.mp3',
            size: 1000,
            mimeType: 'audio/mpeg',
            length: 180.0,
            track: 1,
            disc: 1,
            year: 2024,
        );
        $targetId = $song->getId()->toString();

        // Two strategies pointing at the same target exercise both the
        // aggregation-by-target loop and the target-id collection that
        // previously threw "Cannot access offset of type string on string".
        $recommendations = [
            Recommendation::create('song', Uuid::v7()->toString(), 'song', $targetId, 0.6, $userId, 'content'),
            Recommendation::create('song', Uuid::v7()->toString(), 'song', $targetId, 0.4, $userId, 'genre'),
        ];

        $recommendationRepository = $this->createStub(RecommendationRepositoryInterface::class);
        $recommendationRepository
            ->method('findForUser')
            ->willReturn($recommendations);

        $songRepository = $this->createStub(SongRepositoryInterface::class);
        $songRepository
            ->method('findByUuids')
            ->willReturn([$targetId => $song]);

        $identities = $this->createStub(\App\Recommendation\Application\Port\RecommendationPublicIdentityInterface::class);
        $identities->method('publicEntityId')->willReturn('publicReference1234567');
        $handler = new GetPersonalizedRecommendationsHandler($recommendationRepository, $songRepository, $identities);

        $query = new GetPersonalizedRecommendationsQuery($userId, 12, 'song');
        $result = $handler($query);

        $this->assertCount(1, $result);
        $this->assertSame($song->getPublicId()->toString(), $result[0]['target_id']);
        $this->assertArrayNotHasKey('id', $result[0]['song']);
        $this->assertSame('publicReference1234567', $result[0]['song']['album_id']);
        $this->assertSame(1.0, $result[0]['total_score']);
        // Highest-scoring strategy ('content' = 0.6) drives the explanation.
        $this->assertSame('Similar sound and mood', $result[0]['explanation']);
        $this->assertSame('Test Song', $result[0]['song']['title']);
    }
}
