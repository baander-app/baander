<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Admin;

use App\Metadata\Application\Message\SyncGenresMessage;
use App\Metadata\Application\Port\MetadataAdminPortInterface;
use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class MetadataAdminService implements MetadataAdminPortInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
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

        $totalTracks = (int) $conn->fetchOne('SELECT COUNT(*) FROM songs');
        $syncedTracks = (int) $conn->fetchOne('SELECT COUNT(DISTINCT song_id) FROM genre_song');
        $failedTracks = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM job_monitors WHERE name LIKE '%metadata%' AND status = 'failed'",
        );
        $pendingTracks = max(0, $totalTracks - $syncedTracks - $failedTracks);

        $lastJobQb = $this->entityManager->getRepository(JobMonitorEntity::class)->createQueryBuilder('j');
        $lastJob = $lastJobQb
            ->select('j.createdAt')
            ->where('j.name LIKE :pattern')
            ->setParameter('pattern', '%metadata%')
            ->orderBy('j.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $lastSyncAt = null;
        if ($lastJob !== null && isset($lastJob['createdAt'])) {
            $lastSyncAt = $lastJob['createdAt'] instanceof \DateTimeInterface
                ? $lastJob['createdAt']->format(\DateTimeInterface::ATOM)
                : (string) $lastJob['createdAt'];
        }

        $sources = [];
        $sourceRows = $conn->fetchAllAssociative(
            "SELECT name, SUM(CASE WHEN status = 'finished' THEN 1 ELSE 0 END) as synced,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM job_monitors
             WHERE name LIKE '%metadata%'
             GROUP BY name
             ORDER BY name",
        );

        foreach ($sourceRows as $row) {
            $name = (string) ($row['name'] ?? 'unknown');
            $parts = explode('.', $name);
            $providerName = end($parts);
            $sources[] = [
                'name' => $providerName,
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

    public function triggerSync(?string $source): int
    {
        $this->logger->info('Metadata sync triggered via admin', [
            'source' => $source ?? 'all',
        ]);

        try {
            $this->bus->dispatch(new SyncGenresMessage(
                forceUpdate: $source === 'genres',
                includeSongs: true,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to dispatch sync message', [
                'error' => $e->getMessage(),
            ]);
        }

        return 1;
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
}
