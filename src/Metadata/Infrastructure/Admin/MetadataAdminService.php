<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Admin;

use App\Metadata\Application\Command\SyncMetadataCommand;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Metadata\Application\Message\SyncArtistsMessage;
use App\Metadata\Application\Message\SyncGenresMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Metadata\Application\Port\MetadataAdminPortInterface;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\Settings\MetadataProviderSwitches;
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
        SyncArtistsMessage::class,
        SyncArtistMessage::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MetadataProviderSwitches $switches,
        private readonly string $discogsToken = '',
        private readonly string $lastFmApiKey = '',
        private readonly string $spotifyClientId = '',
        private readonly string $spotifyClientSecret = '',
        private readonly string $tasteDiveApiKey = '',
        private readonly string $tmdbBearerToken = '',
        private readonly string $tvdbApiKey = '',
    ) {}

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
        return array_map($this->getProvider(...), MetadataProvider::cases());
    }

    public function getProvider(MetadataProvider $provider): array
    {
        $configured = match ($provider) {
            MetadataProvider::MusicBrainz, MetadataProvider::CoverArtArchive, MetadataProvider::Local => true,
            MetadataProvider::Discogs => trim($this->discogsToken) !== '',
            MetadataProvider::LastFm => trim($this->lastFmApiKey) !== '',
            MetadataProvider::Spotify => trim($this->spotifyClientId) !== '' && trim($this->spotifyClientSecret) !== '',
            MetadataProvider::TasteDive => trim($this->tasteDiveApiKey) !== '',
            MetadataProvider::Tmdb => trim($this->tmdbBearerToken) !== '',
            MetadataProvider::Tvdb => trim($this->tvdbApiKey) !== '',
        };

        return [
            'id' => $provider->value,
            'name' => $provider->label(),
            'enabled' => $this->switches->enabled($provider),
            'configured' => $configured,
            'attributionText' => $provider->attributionText(),
            'attributionUrl' => $provider->attributionUrl(),
        ];
    }

    /** @return list<string> */
    private static function jobNames(): array
    {
        return array_map(
            static fn(string $class): string => substr($class, (int) strrpos($class, '\\') + 1),
            self::JOB_CLASSES,
        );
    }
}
