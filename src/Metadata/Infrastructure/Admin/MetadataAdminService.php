<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Admin;

use App\Metadata\Application\Command\SyncMetadataCommand;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Metadata\Application\Message\SyncGenresMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Metadata\Application\Port\MetadataAdminPortInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final class MetadataAdminService implements MetadataAdminPortInterface
{
    /**
     * The metadata jobs the job monitor records, by the short class name it stores as the job name.
     */
    private const array JOB_CLASSES = [
        SyncMetadataCommand::class,
        SyncLibraryMessage::class,
        SyncGenresMessage::class,
        SyncAlbumMessage::class,
        SyncSongMessage::class,
        SyncArtistMessage::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $discogsToken = '',
        private readonly string $lastFmApiKey = '',
        private readonly string $spotifyClientId = '',
        private readonly string $spotifyClientSecret = '',
        private readonly string $tasteDiveApiKey = '',
    ) {
    }

    public function getSyncStatus(): array
    {
        $conn = $this->entityManager->getConnection();
        $jobs = ['names' => self::jobNames()];
        $types = ['names' => ArrayParameterType::STRING];

        $totalTracks = (int) $conn->fetchOne('SELECT COUNT(*) FROM songs');
        $syncedTracks = (int) $conn->fetchOne('SELECT COUNT(DISTINCT song_id) FROM genre_song');
        $failedTracks = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM job_monitors WHERE name IN (:names) AND status = 'failed'",
            $jobs,
            $types,
        );
        $pendingTracks = max(0, $totalTracks - $syncedTracks - $failedTracks);

        $lastCreatedAt = $conn->fetchOne('SELECT MAX(created_at) FROM job_monitors WHERE name IN (:names)', $jobs, $types);
        $lastSyncAt = is_string($lastCreatedAt)
            ? (new \DateTimeImmutable($lastCreatedAt))->format(\DateTimeInterface::ATOM)
            : null;

        $sources = [];
        $sourceRows = $conn->fetchAllAssociative(
            "SELECT name, COUNT(*) FILTER (WHERE status = 'finished') AS synced,
                    COUNT(*) FILTER (WHERE status = 'failed') AS failed
             FROM job_monitors
             WHERE name IN (:names)
             GROUP BY name
             ORDER BY name",
            $jobs,
            $types,
        );

        foreach ($sourceRows as $row) {
            $sources[] = [
                'name' => (string) $row['name'],
                'synced' => (int) $row['synced'],
                'failed' => (int) $row['failed'],
            ];
        }

        return [
            'lastSyncAt' => $lastSyncAt,
            'totalTracks' => $totalTracks,
            'syncedTracks' => $syncedTracks,
            'pendingTracks' => $pendingTracks,
            'failedTracks' => $failedTracks,
            'sources' => $sources,
        ];
    }

    public function getProviders(): array
    {
        return [
            [
                'name' => 'MusicBrainz',
                'enabled' => true,
                'configured' => true,
            ],
            [
                'name' => 'Discogs',
                'enabled' => true,
                'configured' => $this->discogsToken !== '',
            ],
            [
                'name' => 'Last.fm',
                'enabled' => true,
                'configured' => $this->lastFmApiKey !== '',
            ],
            [
                'name' => 'Spotify',
                'enabled' => true,
                'configured' => $this->spotifyClientId !== '' && $this->spotifyClientSecret !== '',
            ],
            [
                'name' => 'TasteDive',
                'enabled' => true,
                'configured' => $this->tasteDiveApiKey !== '',
            ],
            [
                'name' => 'CoverArtArchive',
                'enabled' => true,
                'configured' => true,
            ],
        ];
    }

    /** @return list<string> */
    private static function jobNames(): array
    {
        return array_map(
            static fn (string $class): string => substr($class, (int) strrpos($class, '\\') + 1),
            self::JOB_CLASSES,
        );
    }
}
