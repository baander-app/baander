<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Doctrine\Repository;

use App\Catalog\Application\Port\SongPortInterface;
use App\Lyrics\Application\Port\LyricsAdminPortInterface;
use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class LyricsAdminRepository implements LyricsAdminPortInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SongPortInterface $songPort,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getCoverage(): array
    {
        $conn = $this->entityManager->getConnection();

        $totalTracks = (int) $conn->fetchOne('SELECT COUNT(*) FROM songs');

        $tracksWithLyrics = (int) $conn->fetchOne('SELECT COUNT(*) FROM lyrics');

        $tracksWithoutLyrics = max(0, $totalTracks - $tracksWithLyrics);

        $coveragePercentage = $totalTracks > 0
            ? round(($tracksWithLyrics / $totalTracks) * 100, 2)
            : 0.0;

        // Ensure float type even when round returns int
        $coveragePercentage = (float) $coveragePercentage;

        $bySourceRows = $conn->fetchAllAssociative(
            'SELECT source, COUNT(*) as count FROM lyrics GROUP BY source ORDER BY count DESC',
        );

        $bySource = [];
        foreach ($bySourceRows as $row) {
            $bySource[(string) $row['source']] = (int) $row['count'];
        }

        return [
            'totalTracks' => $totalTracks,
            'tracksWithLyrics' => $tracksWithLyrics,
            'tracksWithoutLyrics' => $tracksWithoutLyrics,
            'coveragePercentage' => $coveragePercentage,
            'bySource' => $bySource,
        ];
    }

    public function triggerBulkFetch(array $trackIds = [], ?int $limit = null): int
    {
        $command = new BulkFetchLyricsCommand(
            limit: $limit,
            delayMs: 500,
        );

        $this->bus->dispatch($command);

        $this->logger->info('Bulk lyrics fetch triggered via admin', [
            'trackIds' => count($trackIds),
            'limit' => $limit,
        ]);

        return 1; // One bulk job dispatched
    }

    public function getSyncStatus(): array
    {
        $qb = $this->entityManager->getRepository(JobMonitorEntity::class)->createQueryBuilder('j');

        $recentJobs = (int) $qb
            ->select('COUNT(j.jobId)')
            ->where('j.name LIKE :pattern')
            ->setParameter('pattern', '%lyrics%')
            ->andWhere('j.createdAt >= :since')
            ->setParameter('since', new \DateTimeImmutable('-7 days'))
            ->getQuery()
            ->getSingleScalarResult();

        $failedQb = $this->entityManager->getRepository(JobMonitorEntity::class)->createQueryBuilder('j');
        $failedJobs = (int) $failedQb
            ->select('COUNT(j.jobId)')
            ->where('j.name LIKE :pattern')
            ->setParameter('pattern', '%lyrics%')
            ->andWhere('j.status = :status')
            ->setParameter('status', 'failed')
            ->getQuery()
            ->getSingleScalarResult();

        $completedQb = $this->entityManager->getRepository(JobMonitorEntity::class)->createQueryBuilder('j');
        $completedJobs = (int) $completedQb
            ->select('COUNT(j.jobId)')
            ->where('j.name LIKE :pattern')
            ->setParameter('pattern', '%lyrics%')
            ->andWhere('j.status = :status')
            ->setParameter('status', 'finished')
            ->getQuery()
            ->getSingleScalarResult();

        $lastJobQb = $this->entityManager->getRepository(JobMonitorEntity::class)->createQueryBuilder('j');
        $lastJob = $lastJobQb
            ->select('j.createdAt')
            ->where('j.name LIKE :pattern')
            ->setParameter('pattern', '%lyrics%')
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

        return [
            'lastSyncAt' => $lastSyncAt,
            'recentJobs' => $recentJobs,
            'failedJobs' => $failedJobs,
            'completedJobs' => $completedJobs,
        ];
    }
}
