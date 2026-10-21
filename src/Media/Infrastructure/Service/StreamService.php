<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Service;

use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Domain\Model\TrackStreamMetadata;
use App\Library\Application\Port\LibraryMediaFileResolverInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final class StreamService implements StreamPortInterface
{
    public function __construct(
        private readonly SongRepositoryInterface $songRepository,
        private readonly LibraryMediaFileResolverInterface $mediaFiles,
    ) {
    }

    public function resolveTrackPath(PublicId $trackId): string
    {
        $song = $this->findSong($trackId);
        $libraryId = $this->songRepository->getLibraryIdByPublicId($trackId);
        if ($libraryId === null) {
            throw new \InvalidArgumentException('Track library not found.');
        }

        return $this->mediaFiles->resolve($libraryId, $song->getPath());
    }

    public function getTrackMetadata(PublicId $trackId): TrackStreamMetadata
    {
        $song = $this->findSong($trackId);

        return new TrackStreamMetadata(
            publicId: $song->getPublicId()->toString(),
            filename: basename($song->getPath()),
            filePath: $song->getPath(),
            mimeType: $song->getMimeType(),
            size: $song->getSize(),
            codec: $song->getCodec(),
            bitrate: $song->getBitrate(),
            sampleRate: $song->getSampleRate(),
            channels: $song->getChannels(),
            length: $song->getLength(),
        );
    }

    public function getLibraryIdForTrack(PublicId $trackId): ?Uuid
    {
        return $this->songRepository->getLibraryIdByPublicId($trackId);
    }

    private function findSong(PublicId $trackId): Song
    {
        $song = $this->songRepository->findByPublicId($trackId);

        if ($song === null) {
            throw new \InvalidArgumentException(sprintf('Track not found: %s', $trackId->toString()));
        }

        return $song;
    }
}
