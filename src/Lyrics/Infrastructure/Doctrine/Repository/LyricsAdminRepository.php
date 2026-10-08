<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Doctrine\Repository;

use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\Port\LyricsAdminPortInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final class LyricsAdminRepository implements LyricsAdminPortInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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

    public function getSyncStatus(): array
    {
        $conn = $this->entityManager->getConnection();
        $jobs = ['names' => self::jobNames()];
        $types = ['names' => ArrayParameterType::STRING];

        $row = $conn->fetchAssociative(
            "SELECT COUNT(*) FILTER (WHERE created_at >= :since) AS recent,
                    COUNT(*) FILTER (WHERE status = 'failed') AS failed,
                    COUNT(*) FILTER (WHERE status = 'finished') AS finished,
                    MAX(created_at) AS last_created_at
             FROM job_monitors
             WHERE name IN (:names)",
            $jobs + ['since' => new \DateTimeImmutable('-7 days')],
            $types + ['since' => Types::DATETIME_IMMUTABLE],
        );

        $lastCreatedAt = $row['last_created_at'] ?? null;

        return [
            'lastSyncAt' => is_string($lastCreatedAt)
                ? (new \DateTimeImmutable($lastCreatedAt))->format(\DateTimeInterface::ATOM)
                : null,
            'recentJobs' => (int) ($row['recent'] ?? 0),
            'failedJobs' => (int) ($row['failed'] ?? 0),
            'completedJobs' => (int) ($row['finished'] ?? 0),
        ];
    }

    /**
     * The lyrics jobs by the short class name the job monitor stores as the job name.
     *
     * @return list<string>
     */
    private static function jobNames(): array
    {
        return array_map(
            static fn (string $class): string => substr($class, (int) strrpos($class, '\\') + 1),
            [BulkFetchLyricsCommand::class, FetchLyricsCommand::class],
        );
    }
}
