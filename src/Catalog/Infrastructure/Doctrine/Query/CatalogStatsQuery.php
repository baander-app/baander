<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Query;

use App\Catalog\Application\Port\CatalogStatsQueryPortInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;

final class CatalogStatsQuery implements CatalogStatsQueryPortInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getStatsForLibrary(Uuid $libraryId): array
    {
        $libraryIdStr = $libraryId->toString();

        $songStats = $this->connection->executeQuery(
            "SELECT
                COUNT(s.id) AS songs,
                COALESCE(SUM(s.size), 0) AS total_size,
                COALESCE(SUM(s.length), 0) AS total_duration
             FROM songs s
             JOIN albums a ON a.id = s.album_id
             WHERE a.library_id = :libraryId",
            ['libraryId' => $libraryIdStr],
        )->fetchAssociative();

        $albumCount = (int) $this->connection->executeQuery(
            'SELECT COUNT(id) FROM albums WHERE library_id = :libraryId',
            ['libraryId' => $libraryIdStr],
        )->fetchOne();

        $artistCount = (int) $this->connection->executeQuery(
            "SELECT COUNT(DISTINCT ars.artist_id)
             FROM artist_song ars
             JOIN songs s ON s.id = ars.song_id
             JOIN albums a ON a.id = s.album_id
             WHERE a.library_id = :libraryId",
            ['libraryId' => $libraryIdStr],
        )->fetchOne();

        $genreCount = (int) $this->connection->executeQuery(
            "SELECT COUNT(DISTINCT gs.genre_id)
             FROM genre_song gs
             JOIN songs s ON s.id = gs.song_id
             JOIN albums a ON a.id = s.album_id
             WHERE a.library_id = :libraryId",
            ['libraryId' => $libraryIdStr],
        )->fetchOne();

        return [
            'songs' => (int) ($songStats['songs'] ?? 0),
            'albums' => $albumCount,
            'artists' => $artistCount,
            'genres' => $genreCount,
            'totalSize' => (int) ($songStats['total_size'] ?? 0),
            'totalDuration' => (float) ($songStats['total_duration'] ?? 0),
        ];
    }
}
