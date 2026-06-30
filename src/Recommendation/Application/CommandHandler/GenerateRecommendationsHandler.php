<?php

declare(strict_types=1);

namespace App\Recommendation\Application\CommandHandler;

use App\Activity\Application\Port\ActivityPortInterface;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Recommendation\Application\Command\DeleteRecommendationsBySourceCommand;
use App\Recommendation\Application\Command\GenerateRecommendationsCommand;
use App\Recommendation\Application\Command\SaveRecommendationCommand;
use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Domain\Model\RecommendationJob;
use App\Recommendation\Domain\Service\CollaborativeFilteringCalculator;
use App\Recommendation\Domain\Service\ContentSimilarityCalculator;
use App\Recommendation\Domain\Service\GenreSimilarityCalculator;
use App\Recommendation\Domain\ValueObject\RecommendationType;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class GenerateRecommendationsHandler
{
    private const STRATEGY_COLLABORATIVE = 'collaborative';
    private const STRATEGY_CONTENT = 'content';
    private const STRATEGY_GENRE = 'genre';

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly SongRepositoryInterface $songRepository,
        private readonly ActivityPortInterface $activityPort,
        private readonly CollaborativeFilteringCalculator $collaborativeCalculator,
        private readonly ContentSimilarityCalculator $contentCalculator,
        private readonly GenreSimilarityCalculator $genreCalculator,
        private readonly RecommendationJobPortInterface $jobPort,
        private readonly CpuProcessPool $cpuProcessPool,
        private readonly JsonEncoder $jsonEncoder,
        private readonly string $databaseUrl,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(GenerateRecommendationsCommand $command): array|RecommendationJob
    {
        $isFull = $command->isFull();

        // Use pool worker if available (Swoole context)
        if ($this->cpuProcessPool->isRunning()) {
            return $this->dispatchToPool($isFull, $command->getUserId());
        }

        // Fallback to synchronous execution (CLI context)
        return $this->executeSynchronously($command);
    }

    private function dispatchToPool(bool $isFull, ?Uuid $userId): RecommendationJob
    {
        $metadata = [
            'mode' => $isFull ? 'full' : 'incremental',
            'triggered_by' => $userId?->toString() ?? 'system',
            'triggered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'database_url_hash' => hash('xxh128', $this->databaseUrl),
        ];

        $job = $this->jobPort->create(
            isFull: $isFull,
            userId: $userId,
            metadata: $metadata,
        );

        $key = CpuProcessPool::resultKey('generate_recommendations', $job->getId()->toString());
        $payload = $this->jsonEncoder->encode([
            'type' => 'generate_recommendations',
            'job_id' => $job->getId()->toString(),
            'is_full' => $isFull,
            'database_url' => $this->databaseUrl,
            'metadata' => $metadata,
        ], 'json');

        $this->cpuProcessPool->dispatch($payload, $key);

        return $job;
    }

    private function executeSynchronously(GenerateRecommendationsCommand $command): array
    {
        $userId = $command->getUserId();

        if ($command->isFull()) {
            return $this->generateFull($userId);
        }

        return $this->generateIncremental($userId);
    }

    /**
     * @return array<string, int> Number of recommendations per strategy
     */
    private function generateFull(?Uuid $userId): array
    {
        $songs = $this->songRepository->findAllForRecommendations();
        $listeningHistories = $this->activityPort->getAllListeningHistories();

        $counts = [
            self::STRATEGY_COLLABORATIVE => 0,
            self::STRATEGY_CONTENT => 0,
            self::STRATEGY_GENRE => 0,
        ];

        // Clear existing recommendations for all songs
        foreach ($songs as $song) {
            $this->commandBus->dispatch(new DeleteRecommendationsBySourceCommand(
                sourceType: RecommendationType::fromString('song')->__toString(),
                sourceId: $song->getId()->toString(),
            ));
        }

        // Collaborative filtering (song-based)
        $collaborativeRecs = $this->generateCollaborativeRecommendations($songs, $listeningHistories, $userId);
        foreach ($collaborativeRecs as $rec) {
            $this->commandBus->dispatch($rec);
            $counts[self::STRATEGY_COLLABORATIVE]++;
        }

        // Content similarity (song-based)
        $contentRecs = $this->generateContentRecommendations($songs);
        foreach ($contentRecs as $rec) {
            $this->commandBus->dispatch($rec);
            $counts[self::STRATEGY_CONTENT]++;
        }

        // Genre similarity (song-based)
        $genreRecs = $this->generateGenreRecommendations($songs);
        foreach ($genreRecs as $rec) {
            $this->commandBus->dispatch($rec);
            $counts[self::STRATEGY_GENRE]++;
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function generateIncremental(?Uuid $userId): array
    {
        $since = new \DateTimeImmutable('7 days ago');

        $songs = $this->songRepository->findUpdatedAfter($since);
        $listeningHistories = $this->activityPort->getAllListeningHistories();

        $counts = [
            self::STRATEGY_COLLABORATIVE => 0,
            self::STRATEGY_CONTENT => 0,
            self::STRATEGY_GENRE => 0,
        ];

        foreach ($songs as $song) {
            $this->commandBus->dispatch(new DeleteRecommendationsBySourceCommand(
                sourceType: RecommendationType::fromString('song')->__toString(),
                sourceId: $song->getId()->toString(),
            ));
        }

        $collaborativeRecs = $this->generateCollaborativeRecommendations($songs, $listeningHistories, $userId);
        foreach ($collaborativeRecs as $rec) {
            $this->commandBus->dispatch($rec);
            $counts[self::STRATEGY_COLLABORATIVE]++;
        }

        $contentRecs = $this->generateContentRecommendations($songs);
        foreach ($contentRecs as $rec) {
            $this->commandBus->dispatch($rec);
            $counts[self::STRATEGY_CONTENT]++;
        }

        $genreRecs = $this->generateGenreRecommendations($songs);
        foreach ($genreRecs as $rec) {
            $this->commandBus->dispatch($rec);
            $counts[self::STRATEGY_GENRE]++;
        }

        return $counts;
    }

    /**
     * @param \App\Catalog\Domain\Model\Song[] $songs
     * @param array<string, array<string, int>> $listeningHistories
     * @return SaveRecommendationCommand[]
     */
    private function generateCollaborativeRecommendations(array $songs, array $listeningHistories, ?Uuid $userId): array
    {
        $commands = [];

        foreach ($songs as $sourceSong) {
            $sourceId = $sourceSong->getId()->toString();

            $coOccurrences = $this->collaborativeCalculator->coOccurrence(
                itemId: $sourceId,
                userHistories: $listeningHistories,
                limit: 15,
            );

            foreach ($coOccurrences as $rec) {
                $targetId = $rec['id'];
                $score = $rec['score'];

                if ($score < 0.01) {
                    continue;
                }

                $commands[] = new SaveRecommendationCommand(
                    sourceType: RecommendationType::fromString('song'),
                    sourceId: $sourceId,
                    targetType: RecommendationType::fromString('song'),
                    targetId: $targetId,
                    score: $score,
                    userId: $userId,
                    name: self::STRATEGY_COLLABORATIVE,
                );
            }
        }

        return $commands;
    }

    /**
     * @param \App\Catalog\Domain\Model\Song[] $songs
     * @return SaveRecommendationCommand[]
     */
    private function generateContentRecommendations(array $songs): array
    {
        $commands = [];

        foreach ($songs as $sourceSong) {
            $sourceId = $sourceSong->getId()->toString();
            $sourceFeatures = $this->extractFeatures($sourceSong);

            $candidates = [];
            foreach ($songs as $candidate) {
                if ($candidate->getId()->toString() === $sourceId) {
                    continue;
                }
                $candidates[] = [
                    'id' => $candidate->getId()->toString(),
                    'features' => $this->extractFeatures($candidate),
                ];
            }

            $similar = $this->contentCalculator->findMostSimilar($sourceFeatures, $candidates, limit: 15);

            foreach ($similar as $rec) {
                $targetId = $rec['id'] ?? '';
                $score = $rec['score'] ?? 0;

                if ($score < 0.1) {
                    continue;
                }

                $commands[] = new SaveRecommendationCommand(
                    sourceType: RecommendationType::fromString('song'),
                    sourceId: $sourceId,
                    targetType: RecommendationType::fromString('song'),
                    targetId: $targetId,
                    score: $score,
                    userId: null,
                    name: self::STRATEGY_CONTENT,
                );
            }
        }

        return $commands;
    }

    /**
     * @param \App\Catalog\Domain\Model\Song[] $songs
     * @return SaveRecommendationCommand[]
     */
    private function generateGenreRecommendations(array $songs): array
    {
        $commands = [];

        if ($songs === []) {
            return $commands;
        }

        $songIds = array_map(fn ($s) => $s->getId(), $songs);
        $genreMap = $this->songRepository->getGenreNamesForSongs($songIds);

        foreach ($songs as $sourceSong) {
            $sourceId = $sourceSong->getId()->toString();
            $sourceGenres = $genreMap[$sourceId] ?? [];

            foreach ($songs as $targetSong) {
                $targetId = $targetSong->getId()->toString();
                if ($targetId === $sourceId) {
                    continue;
                }

                $targetGenres = $genreMap[$targetId] ?? [];

                $similarity = $this->genreCalculator->jaccardSimilarity($sourceGenres, $targetGenres);
                if ($similarity < 0.3) {
                    continue;
                }

                $commands[] = new SaveRecommendationCommand(
                    sourceType: RecommendationType::fromString('song'),
                    sourceId: $sourceId,
                    targetType: RecommendationType::fromString('song'),
                    targetId: $targetId,
                    score: $similarity,
                    userId: null,
                    name: self::STRATEGY_GENRE,
                );
            }
        }

        return $commands;
    }

    /**
     * @return array<string, float>
     */
    private function extractFeatures(\App\Catalog\Domain\Model\Song $song): array
    {
        return [
            'energy' => $song->getEnergy() ?? 0.5,
            'danceability' => $song->getDanceability() ?? 0.5,
            'valence' => $song->getValence() ?? 0.5,
            'acousticness' => $song->getAcousticness() ?? 0.5,
            'instrumentalness' => $song->getInstrumentalness() ?? 0.5,
            'spechiness' => $song->getSpechiness() ?? 0.5,
            'loudness' => (max(-60, min(0, $song->getLoudness() ?? -10)) + 60) / 60,
        ];
    }
}
