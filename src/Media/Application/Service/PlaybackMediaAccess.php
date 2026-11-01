<?php

declare(strict_types=1);

namespace App\Media\Application\Service;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final readonly class PlaybackMediaAccess implements PlaybackMediaAccessInterface
{
    public function __construct(
        private MediaReadScopeProviderInterface $scopes,
        private StreamPortInterface $streams,
        private VideoPortInterface $videos,
    ) {}

    public function assertTrackAccess(Uuid $owner, PublicId $track): void
    {
        $scope = $this->scopes->forUser($owner);
        $library = $this->streams->getLibraryIdForTrack($track);
        if ($library === null || !$scope->getLibraries()->allows($library)) {
            throw new ConflictException('Playback media is unavailable.');
        }
    }

    public function assertVideoAccess(Uuid $owner, PublicId $video): void
    {
        $scope = $this->scopes->forUser($owner);
        if (!$this->videos->isVisibleByPublicId($video, $scope->getLibraries())) {
            throw new ConflictException('Playback media is unavailable.');
        }
    }
}
