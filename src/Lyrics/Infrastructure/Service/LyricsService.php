<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Service;

use App\Lyrics\Application\Port\LyricsPortInterface;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Infrastructure implementation of LyricsPortInterface: reads the lyrics stored for a song.
 */
final class LyricsService implements LyricsPortInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LyricsRepositoryInterface $lyricsRepository,
    ) {
    }

    public function findBySongId(Uuid $songId): ?Lyrics
    {
        $this->logger->debug('Fetching lyrics by song ID', [
            'song_id' => $songId->toString(),
        ]);

        return $this->lyricsRepository->findBySongId($songId);
    }
}
