<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine\Repository;

use App\Activity\Application\Port\ActivityAnalyticsPortInterface;
use Doctrine\DBAL\Connection;

final class ActivityAnalyticsRepository implements ActivityAnalyticsPortInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getSummary(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $sql = <<<SQL
            SELECT
                COALESCE(SUM(m.play_count), 0) AS total_plays,
                COUNT(DISTINCT m.song_id) AS unique_tracks,
                COUNT(DISTINCT m.artist_id) AS unique_artists,
                COALESCE(SUM(m.play_count * COALESCE(s.length, 0)), 0) AS total_listening_time
            FROM media_activities m
            LEFT JOIN songs s ON s.id = m.song_id
            WHERE m.last_played_at >= :from
              AND m.last_played_at <= :to
              AND m.play_count > 0
        SQL;

        $row = $this->connection->executeQuery($sql, [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ])->fetchAssociative();

        return [
            'total_plays' => (int) ($row['total_plays'] ?? 0),
            'unique_tracks' => (int) ($row['unique_tracks'] ?? 0),
            'unique_artists' => (int) ($row['unique_artists'] ?? 0),
            'total_listening_time' => (int) ($row['total_listening_time'] ?? 0),
        ];
    }

    public function getTopTracks(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 10): array
    {
        $sql = <<<SQL
            SELECT
                s.title AS track_name,
                ar.name AS artist_name,
                al.title AS album_name,
                SUM(m.play_count) AS play_count
            FROM media_activities m
            INNER JOIN songs s ON s.id = m.song_id
            LEFT JOIN artists ar ON ar.id = m.artist_id
            LEFT JOIN albums al ON al.id = m.album_id
            WHERE m.last_played_at >= :from
              AND m.last_played_at <= :to
              AND m.play_count > 0
            GROUP BY s.title, ar.name, al.title
            ORDER BY play_count DESC
            LIMIT :limit
        SQL;

        $rows = $this->connection->executeQuery($sql, [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ])->fetchAllAssociative();

        return array_map(fn (array $row): array => [
            'track_name' => $row['track_name'],
            'artist_name' => $row['artist_name'],
            'album_name' => $row['album_name'],
            'play_count' => (int) $row['play_count'],
        ], $rows);
    }

    public function getTopArtists(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 10): array
    {
        $sql = <<<SQL
            SELECT
                ar.name AS artist_name,
                SUM(m.play_count) AS play_count
            FROM media_activities m
            INNER JOIN artists ar ON ar.id = m.artist_id
            WHERE m.last_played_at >= :from
              AND m.last_played_at <= :to
              AND m.play_count > 0
            GROUP BY ar.name
            ORDER BY play_count DESC
            LIMIT :limit
        SQL;

        $rows = $this->connection->executeQuery($sql, [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ])->fetchAllAssociative();

        return array_map(fn (array $row): array => [
            'artist_name' => $row['artist_name'],
            'play_count' => (int) $row['play_count'],
        ], $rows);
    }

    public function getEngagement(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $sql = <<<SQL
            SELECT
                COUNT(DISTINCT m.user_id) AS active_users,
                COALESCE(SUM(m.play_count), 0) AS total_plays,
                COALESCE(SUM(m.play_count * COALESCE(s.length, 0)), 0) AS total_listening_time
            FROM media_activities m
            LEFT JOIN songs s ON s.id = m.song_id
            WHERE m.last_played_at >= :from
              AND m.last_played_at <= :to
              AND m.play_count > 0
        SQL;

        $row = $this->connection->executeQuery($sql, [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ])->fetchAssociative();

        $activeUsers = (int) ($row['active_users'] ?? 0);
        $totalPlays = (int) ($row['total_plays'] ?? 0);
        $totalListeningTime = (int) ($row['total_listening_time'] ?? 0);

        $avgPlaysPerUser = $activeUsers > 0 ? round($totalPlays / $activeUsers, 2) : 0.0;
        $avgSessionLength = $activeUsers > 0 ? round($totalListeningTime / $activeUsers, 2) : 0.0;

        return [
            'active_users' => $activeUsers,
            'avg_plays_per_user' => $avgPlaysPerUser,
            'avg_session_length' => $avgSessionLength,
        ];
    }
}
