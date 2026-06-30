<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Swoole\ProcessPool\ProcessPoolWorkerInterface;
use RuntimeException;

/**
 * Pool worker that executes recommendation generation in an isolated process.
 *
 * Receives a job ID, queries the database directly, computes recommendations,
 * and saves them. Runs without Symfony container.
 */
final class RecommendationPoolWorker implements ProcessPoolWorkerInterface
{
    private const STRATEGY_COLLABORATIVE = 'collaborative';
    private const STRATEGY_CONTENT = 'content';
    private const STRATEGY_GENRE = 'genre';

    public function supportedTypes(): array
    {
        return ['generate_recommendations'];
    }

    public function handle(string $payload): string
    {
        $job = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        $jobId = $job['job_id'] ?? null;
        $isFull = $job['is_full'] ?? false;
        $databaseUrl = $job['database_url'] ?? null;

        if (!$jobId || !$databaseUrl) {
            throw new RuntimeException('Missing required fields: job_id, database_url');
        }

        try {
            $pdo = $this->createPdo($databaseUrl);
            $result = $this->generateRecommendations($pdo, $jobId, $isFull);

            return json_encode(['success' => true, 'counts' => $result]);
        } catch (\Throwable $e) {
            // Mark job as failed
            try {
                $pdo = $this->createPdo($databaseUrl);
                $this->updateJobStatus($pdo, $jobId, 'failed', $e->getMessage());
            } catch (\Throwable) {
                // Ignore failure updates
            }

            throw new RuntimeException('Recommendation generation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, int>
     */
    private function generateRecommendations(\PDO $pdo, string $jobId, bool $isFull): array
    {
        // Mark job as in progress
        $this->updateJobStatus($pdo, $jobId, 'in_progress');

        // Get all songs with features
        $songs = $this->fetchAllSongs($pdo);

        // Get listening histories
        $listeningHistories = $this->fetchListeningHistories($pdo);

        $totalSongs = count($songs);
        $this->updateJobProgress($pdo, $jobId, $totalSongs, 0, 'starting');

        // Clear existing recommendations if full generation
        if ($isFull) {
            $this->clearAllRecommendations($pdo);
        }

        $counts = [
            self::STRATEGY_COLLABORATIVE => 0,
            self::STRATEGY_CONTENT => 0,
            self::STRATEGY_GENRE => 0,
        ];

        // Collaborative filtering
        $this->updateJobProgress($pdo, $jobId, $totalSongs, 0, self::STRATEGY_COLLABORATIVE);
        $collabCounts = $this->generateCollaborativeRecommendations($pdo, $songs, $listeningHistories, $jobId);
        $counts[self::STRATEGY_COLLABORATIVE] = $collabCounts;

        // Content similarity
        $completed = $collabCounts;
        $this->updateJobProgress($pdo, $jobId, $totalSongs, $completed, self::STRATEGY_CONTENT);
        $contentCounts = $this->generateContentRecommendations($pdo, $songs, $jobId);
        $counts[self::STRATEGY_CONTENT] = $contentCounts;

        // Genre similarity
        $completed += $contentCounts;
        $this->updateJobProgress($pdo, $jobId, $totalSongs, $completed, self::STRATEGY_GENRE);
        $genreCounts = $this->generateGenreRecommendations($pdo, $songs, $jobId);
        $counts[self::STRATEGY_GENRE] = $genreCounts;

        // Mark job as completed
        $this->updateJobProgress($pdo, $jobId, $totalSongs, $totalSongs, 'completed');
        $this->updateJobStatus($pdo, $jobId, 'completed', null, $counts);

        return $counts;
    }

    /**
     * @return list<array{id: string, features: array<string, float>}>
     */
    private function fetchAllSongs(\PDO $pdo): array
    {
        $stmt = $pdo->prepare("
            SELECT id, energy, danceability, valence, acousticness,
                   instrumentalness, spechiness, loudness
            FROM songs
            WHERE deleted_at IS NULL
        ");
        $stmt->execute();

        $songs = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $songs[] = [
                'id' => $row['id'],
                'features' => $this->extractFeatures($row),
            ];
        }

        return $songs;
    }

    /**
     * @return array<string, array<string, int>> Map of user_id => [song_id => play_count]
     */
    private function fetchListeningHistories(\PDO $pdo): array
    {
        $stmt = $pdo->prepare("
            SELECT user_id, song_id, COUNT(*) as play_count
            FROM media_activities
            WHERE action = 'play'
            GROUP BY user_id, song_id
        ");
        $stmt->execute();

        $histories = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $userId = $row['user_id'];
            $songId = $row['song_id'];
            if (!isset($histories[$userId])) {
                $histories[$userId] = [];
            }
            $histories[$userId][$songId] = (int) $row['play_count'];
        }

        return $histories;
    }

    /**
     * @param list<array{id: string, features: array<string, float>}> $songs
     * @param array<string, array<string, int>> $listeningHistories
     */
    private function generateCollaborativeRecommendations(\PDO $pdo, array $songs, array $listeningHistories, string $jobId): int
    {
        $count = 0;

        foreach ($songs as $sourceSong) {
            $sourceId = $sourceSong['id'];

            $coOccurrences = $this->coOccurrence($sourceId, $listeningHistories, 15);

            foreach ($coOccurrences as $rec) {
                $targetId = $rec['id'];
                $score = $rec['score'];

                if ($score < 0.01) {
                    continue;
                }

                $this->insertRecommendation($pdo, $sourceId, $targetId, $score, self::STRATEGY_COLLABORATIVE, null);
                $count++;
            }

            // Check for cancellation every 50 songs
            if ($count % 50 === 0 && $this->isJobCancelled($pdo, $jobId)) {
                throw new RuntimeException('Job cancelled');
            }
        }

        return $count;
    }

    /**
     * @param list<array{id: string, features: array<string, float>}> $songs
     */
    private function generateContentRecommendations(\PDO $pdo, array $songs, string $jobId): int
    {
        $count = 0;

        foreach ($songs as $sourceSong) {
            $sourceId = $sourceSong['id'];
            $sourceFeatures = $sourceSong['features'];

            $candidates = [];
            foreach ($songs as $candidate) {
                if ($candidate['id'] === $sourceId) {
                    continue;
                }
                $candidates[] = [
                    'id' => $candidate['id'],
                    'features' => $candidate['features'],
                ];
            }

            $similar = $this->findMostSimilar($sourceFeatures, $candidates, 15);

            foreach ($similar as $rec) {
                $targetId = $rec['id'];
                $score = $rec['score'];

                if ($score < 0.1) {
                    continue;
                }

                $this->insertRecommendation($pdo, $sourceId, $targetId, $score, self::STRATEGY_CONTENT, null);
                $count++;
            }

            if ($count % 50 === 0 && $this->isJobCancelled($pdo, $jobId)) {
                throw new RuntimeException('Job cancelled');
            }
        }

        return $count;
    }

    /**
     * @param list<array{id: string, features: array<string, float>}> $songs
     */
    private function generateGenreRecommendations(\PDO $pdo, array $songs, string $jobId): int
    {
        if ($songs === []) {
            return 0;
        }

        // Fetch genre names for all songs
        $songIds = array_column($songs, 'id');
        $inClause = implode(',', array_fill(0, count($songIds), '?'));

        $stmt = $pdo->prepare("
            SELECT sg.song_id, g.name
            FROM song_genres sg
            JOIN genres g ON sg.genre_id = g.id
            WHERE sg.song_id IN ($inClause)
        ");
        $stmt->execute($songIds);

        $genreMap = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $songId = $row['song_id'];
            if (!isset($genreMap[$songId])) {
                $genreMap[$songId] = [];
            }
            $genreMap[$songId][] = $row['name'];
        }

        $count = 0;
        foreach ($songs as $sourceSong) {
            $sourceId = $sourceSong['id'];
            $sourceGenres = $genreMap[$sourceId] ?? [];

            foreach ($songs as $targetSong) {
                $targetId = $targetSong['id'];
                if ($targetId === $sourceId) {
                    continue;
                }

                $targetGenres = $genreMap[$targetId] ?? [];

                $similarity = $this->jaccardSimilarity($sourceGenres, $targetGenres);
                if ($similarity < 0.3) {
                    continue;
                }

                $this->insertRecommendation($pdo, $sourceId, $targetId, $similarity, self::STRATEGY_GENRE, null);
                $count++;
            }

            if ($count % 50 === 0 && $this->isJobCancelled($pdo, $jobId)) {
                throw new RuntimeException('Job cancelled');
            }
        }

        return $count;
    }

    private function insertRecommendation(\PDO $pdo, string $sourceId, string $targetId, float $score, string $name, ?string $userId): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO recommendations (id, public_id, source_type, source_id, target_type, target_id, score, user_id, name, position, created_at, updated_at)
            VALUES (?, ?, 'song', ?, 'song', ?, ?, ?, ?, 0, NOW(), NOW())
            ON CONFLICT (source_type, source_id, target_type, target_id, name, user_id)
            DO UPDATE SET score = EXCLUDED.score, updated_at = NOW()
        ");
        $id = $this->generateUuid();
        $publicId = $this->generateNanoid();

        $stmt->execute([$id, $publicId, $sourceId, $targetId, $score, $userId, $name]);
    }

    private function clearAllRecommendations(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM recommendations WHERE source_type = 'song' AND target_type = 'song'");
    }

    private function updateJobStatus(\PDO $pdo, string $jobId, string $status, ?string $failReason = null, ?array $strategyCounts = null): void
    {
        $fields = ['status' => $status, 'updated_at' => new \DateTime()];
        if ($failReason !== null) {
            $fields['fail_reason'] = $failReason;
        }
        if ($strategyCounts !== null) {
            $fields['strategy_counts'] = json_encode($strategyCounts);
        }
        if ($status === 'completed') {
            $fields['completed_at'] = new \DateTime();
        } elseif ($status === 'in_progress') {
            $fields['started_at'] = new \DateTime();
        }

        $setParts = [];
        $params = [];
        foreach ($fields as $key => $value) {
            $setParts[] = "$key = ?";
            if ($value instanceof \DateTime) {
                $params[] = $value->format('Y-m-d H:i:s');
            } else {
                $params[] = $value;
            }
        }
        $params[] = $jobId;

        $stmt = $pdo->prepare("UPDATE recommendation_jobs SET " . implode(', ', $setParts) . " WHERE id = ?");
        $stmt->execute($params);
    }

    private function updateJobProgress(\PDO $pdo, string $jobId, int $totalSongs, int $completedSongs, string $currentStrategy): void
    {
        $stmt = $pdo->prepare("
            UPDATE recommendation_jobs
            SET total_songs = ?, completed_songs = ?, current_strategy = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$totalSongs, $completedSongs, $currentStrategy, $jobId]);
    }

    private function isJobCancelled(\PDO $pdo, string $jobId): bool
    {
        $stmt = $pdo->prepare("SELECT status FROM recommendation_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row && $row['status'] === 'cancelled';
    }

    private function createPdo(string $databaseUrl): \PDO
    {
        $url = parse_url($databaseUrl);

        if ($url === false || !isset($url['host'], $url['path'])) {
            throw new RuntimeException('Invalid DATABASE_URL format');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $url['host'],
            $url['port'] ?? 5432,
            ltrim($url['path'], '/')
        );

        $pdo = new \PDO($dsn, $url['user'] ?? null, $url['pass'] ?? null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        return $pdo;
    }

    /**
     * @return array<string, float>
     */
    private function extractFeatures(array $row): array
    {
        $loudness = (float) ($row['loudness'] ?? -10);
        $normalizedLoudness = (max(-60, min(0, $loudness)) + 60) / 60;

        return [
            'energy' => (float) ($row['energy'] ?? 0.5),
            'danceability' => (float) ($row['danceability'] ?? 0.5),
            'valence' => (float) ($row['valence'] ?? 0.5),
            'acousticness' => (float) ($row['acousticness'] ?? 0.5),
            'instrumentalness' => (float) ($row['instrumentalness'] ?? 0.5),
            'spechiness' => (float) ($row['spechiness'] ?? 0.5),
            'loudness' => $normalizedLoudness,
        ];
    }

    /**
     * @param array<string, array<string, int>> $userHistories
     * @return list<array{id: string, score: float}>
     */
    private function coOccurrence(string $itemId, array $userHistories, int $limit): array
    {
        $coOccurrences = [];

        foreach ($userHistories as $items) {
            if (!isset($items[$itemId])) {
                continue;
            }

            foreach ($items as $otherItemId => $playCount) {
                if ($otherItemId === $itemId) {
                    continue;
                }

                if (!isset($coOccurrences[$otherItemId])) {
                    $coOccurrences[$otherItemId] = 0;
                }
                $coOccurrences[$otherItemId] += $playCount;
            }
        }

        arsort($coOccurrences);

        $total = array_sum($coOccurrences) ?: 1;
        $results = [];

        foreach (array_slice($coOccurrences, 0, $limit, true) as $id => $count) {
            $results[] = [
                'id' => $id,
                'score' => $count / $total,
            ];
        }

        return $results;
    }

    /**
     * @param array<string, float> $target
     * @param list<array{id: string, features: array<string, float>}> $candidates
     * @return list<array{id: string, score: float}>
     */
    private function findMostSimilar(array $target, array $candidates, int $limit): array
    {
        $scores = [];
        foreach ($candidates as $candidate) {
            $similarity = $this->cosineSimilarity($target, $candidate['features']);
            $scores[] = ['id' => $candidate['id'], 'score' => $similarity];
        }

        usort($scores, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scores, 0, $limit);
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $keys = array_intersect(array_keys($a), array_keys($b));

        if ($keys === []) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($keys as $key) {
            $dotProduct += $a[$key] * $b[$key];
            $normA += $a[$key] ** 2;
            $normB += $b[$key] ** 2;
        }

        $denominator = sqrt($normA) * sqrt($normB);

        if ($denominator === 0.0) {
            return 0.0;
        }

        return $dotProduct / $denominator;
    }

    private function jaccardSimilarity(array $a, array $b): float
    {
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x70); // version 7
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function generateNanoid(): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
        $id = '';
        for ($i = 0; $i < 21; $i++) {
            $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $id;
    }
}
