<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Model;

use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class VideoPlacementTest extends TestCase
{
    public function testParsedClassificationCanMoveAMovieFileBetweenFeatureAndExtra(): void
    {
        $placement = $this->moviePlacement();

        $placement->applyParsedExtraKind(ExtraKind::Trailer);
        self::assertSame(ExtraKind::Trailer, $placement->getExtraKind());
        $placement->applyParsedExtraKind(null);
        self::assertNull($placement->getExtraKind());
        self::assertFalse($placement->isAssigned());
    }

    public function testParsedClassificationKeepsAnExplicitExtraAssignment(): void
    {
        $placement = $this->moviePlacement();
        $placement->assignExtra(ExtraKind::Interview, assigned: true);

        $placement->applyParsedExtraKind(null);

        self::assertSame(ExtraKind::Interview, $placement->getExtraKind());
        self::assertTrue($placement->isAssigned());
    }

    public function testAMovieFeatureAssignmentCopiesWithoutEpisodeIds(): void
    {
        $previous = $this->moviePlacement();
        $state = clone $previous->getState();
        $state->assigned = true;
        $previous = VideoPlacement::reconstitute($state);
        $replacement = $this->moviePlacement();
        $replacement->applyParsedExtraKind(ExtraKind::Trailer);

        $replacement->copyAssignmentFrom($previous);

        self::assertNull($replacement->getExtraKind());
        self::assertTrue($replacement->isAssigned());
        self::assertSame([], $replacement->getEpisodeIds());
    }

    public function testEpisodeAssignmentsKeepTheirEpisodeIdsWhenCopied(): void
    {
        $series = Uuid::v7();
        $previous = VideoPlacement::create(Uuid::v7(), Uuid::v7(), 'Show/S01E01.mp4', [], seriesId: $series);
        $episode = Uuid::v7();
        $previous->assignEpisodes([$episode], assigned: true);
        $replacement = VideoPlacement::create(Uuid::v7(), Uuid::v7(), 'Show/S01E01.mp4', [], seriesId: $series);

        $replacement->copyAssignmentFrom($previous);

        self::assertSame([$episode], $replacement->getEpisodeIds());
        self::assertTrue($replacement->isAssigned());
    }

    private function moviePlacement(): VideoPlacement
    {
        return VideoPlacement::create(
            Uuid::v7(),
            Uuid::v7(),
            'Movie (1999)/Feature.mp4',
            [],
            movieId: Uuid::v7(),
        );
    }
}
