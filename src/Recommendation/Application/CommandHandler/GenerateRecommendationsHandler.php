<?php

declare(strict_types=1);

namespace App\Recommendation\Application\CommandHandler;

use App\Activity\Application\Port\ActivityPortInterface;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Recommendation\Application\Command\DeleteRecommendationsBySourceCommand;
use App\Recommendation\Application\Command\GenerateRecommendationsCommand;
use App\Recommendation\Application\Command\SaveRecommendationCommand;
use App\Recommendation\Application\DTO\RecommendationGenerationResult;
use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Application\Settings\RecommendationSettingDefinitions;
use App\Recommendation\Domain\Model\RecommendationJob;
use App\Recommendation\Domain\Service\CollaborativeFilteringCalculator;
use App\Recommendation\Domain\Service\ContentSimilarityCalculator;
use App\Recommendation\Domain\Service\GenreSimilarityCalculator;
use App\Recommendation\Domain\ValueObject\RecommendationJobStatus;
use App\Recommendation\Domain\ValueObject\RecommendationType;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Throwable;

/**
 * Runs a recommendation job: the job record is created first on every path, so each run
 * shows in the admin job list and can be cancelled. In the web server the job goes to the
 * CPU process pool; elsewhere, such as from the console or the scheduler worker, it runs
 * here and the record follows each stage.
 */
final class GenerateRecommendationsHandler
{
    /** The scheduler records this as the run's last result. */
    public const string SKIPPED_AUTO_GENERATE_OFF = 'skipped: ' . RecommendationSettingDefinitions::AUTO_GENERATE . ' is off';

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
        private readonly SystemSettingsPortInterface $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return RecommendationGenerationResult|string the string is the skip of an automatic run
     *
     * @throws InvalidInputException for an unknown mode
     * @throws NotFoundException     for an unknown job ID
     * @throws ConflictException     when the given job is no longer pending
     */
    #[AsMessageHandler]
    public function __invoke(GenerateRecommendationsCommand $command): RecommendationGenerationResult|string
    {
        if (!$command->isFull() && !$command->isIncremental()) {
            throw new InvalidInputException(
                sprintf('Unknown recommendation generation mode "%s". Use "full" or "incremental".', $command->getMode()),
                ['mode' => $command->getMode()],
            );
        }

        // Read when the run fires, so an admin's change applies to the next run.
        if ($command->isAutomatic() && $this->settings->get(RecommendationSettingDefinitions::AUTO_GENERATE) !== true) {
            $this->logger->info('Scheduled recommendation generation skipped: ' . RecommendationSettingDefinitions::AUTO_GENERATE . ' is off.');

            return self::SKIPPED_AUTO_GENERATE_OFF;
        }

        $job = $this->job($command);

        // Use pool worker if available (Swoole context)
        if ($this->cpuProcessPool->isRunning()) {
            return $this->dispatchToPool($job);
        }

        return $this->runHere($job);
    }

    private function job(GenerateRecommendationsCommand $command): RecommendationJob
    {
        $jobId = $command->getJobId();
        if ($jobId === null) {
            return $this->jobPort->create(
                isFull: $command->isFull(),
                userId: $command->getUserId(),
                metadata: [
                    'mode' => $command->getMode(),
                    'triggered_by' => $command->getActor() ?? 'system',
                    'triggered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'database_url_hash' => hash('xxh128', $this->databaseUrl),
                ],
            );
        }

        $job = $this->jobPort->getById($jobId)
            ?? throw new NotFoundException('Recommendation job not found.', ['jobId' => $jobId->toString()]);
        if ($job->getStatus() !== RecommendationJobStatus::Pending) {
            throw new ConflictException(
                sprintf('Only a pending recommendation job can be run; this one is %s.', $job->getStatus()->value),
                ['status' => $job->getStatus()->value],
            );
        }

        return $job;
    }

    private function dispatchToPool(RecommendationJob $job): RecommendationGenerationResult
    {
        $key = CpuProcessPool::resultKey('generate_recommendations', $job->getId()->toString());
        $payload = $this->jsonEncoder->encode([
            'type' => 'generate_recommendations',
            'job_id' => $job->getId()->toString(),
            'is_full' => $job->isFull(),
            'database_url' => $this->databaseUrl,
            'metadata' => $job->getMetadata(),
        ], 'json');

        $this->cpuProcessPool->dispatch($payload, $key);

        return $this->result($job, RecommendationGenerationResult::EXECUTION_ASYNC);
    }

    /**
     * Runs the job in this process. The record moves to in progress, names each strategy as
     * it starts, and ends completed, failed with the error, or cancelled when an admin
     * cancelled it between strategies.
     */
    private function runHere(RecommendationJob $job): RecommendationGenerationResult
    {
        if ($this->jobPort->isCancelled($job->getId())) {
            return $this->result($job, RecommendationGenerationResult::EXECUTION_SYNC, RecommendationJobStatus::Cancelled);
        }

        try {
            $songs = $job->isFull()
                ? $this->songRepository->findAllForRecommendations()
                : $this->songRepository->findUpdatedAfter(new \DateTimeImmutable('7 days ago'));
            $job->markInProgress(count($songs));
            $this->jobPort->save($job);

            [$counts, $cancelled] = $this->generate($job, $songs);
            if ($cancelled || $this->jobPort->isCancelled($job->getId())) {
                return $this->result($job, RecommendationGenerationResult::EXECUTION_SYNC, RecommendationJobStatus::Cancelled, $counts);
            }

            $job->markCompleted($counts);
            $this->jobPort->save($job);
        } catch (Throwable $failure) {
            $this->recordFailure($job, $failure);

            throw $failure;
        }

        return $this->result($job, RecommendationGenerationResult::EXECUTION_SYNC, counts: $counts);
    }

    /**
     * Replaces the recommendations of the given songs, one strategy after another.
     *
     * @param \App\Catalog\Domain\Model\Song[] $songs
     *
     * @return array{0: array<string, int>, 1: bool} the counts per strategy, and whether the job was cancelled
     */
    private function generate(RecommendationJob $job, array $songs): array
    {
        $userId = $job->getUserId();
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

        $strategies = [
            self::STRATEGY_COLLABORATIVE => fn (): array => $this->generateCollaborativeRecommendations(
                $songs,
                $this->activityPort->getAllListeningHistories(),
                $userId,
            ),
            self::STRATEGY_CONTENT => fn (): array => $this->generateContentRecommendations($songs),
            self::STRATEGY_GENRE => fn (): array => $this->generateGenreRecommendations($songs),
        ];

        foreach ($strategies as $strategy => $recommendations) {
            if ($this->jobPort->isCancelled($job->getId())) {
                return [$counts, true];
            }

            $job->updateProgress($strategy, 0, $counts);
            $this->jobPort->save($job);

            foreach ($recommendations() as $recommendation) {
                $this->commandBus->dispatch($recommendation);
                $counts[$strategy]++;
            }
        }

        return [$counts, false];
    }

    /** Marks the job failed without hiding the run's own error if recording it fails too. */
    private function recordFailure(RecommendationJob $job, Throwable $failure): void
    {
        try {
            $job->markFailed($failure->getMessage());
            $this->jobPort->save($job);
        } catch (Throwable $recordFailure) {
            $this->logger->error('Could not mark the recommendation job failed.', [
                'job_id' => $job->getId()->toString(),
                'error' => $failure->getMessage(),
                'record_error' => $recordFailure->getMessage(),
            ]);
        }
    }

    /** @param array<string, int> $counts */
    private function result(
        RecommendationJob $job,
        string $execution,
        ?RecommendationJobStatus $status = null,
        array $counts = [],
    ): RecommendationGenerationResult {
        return new RecommendationGenerationResult(
            jobId: $job->getId()->toString(),
            publicId: $job->getPublicId()->toString(),
            mode: $job->isFull() ? GenerateRecommendationsCommand::MODE_FULL : GenerateRecommendationsCommand::MODE_INCREMENTAL,
            status: ($status ?? $job->getStatus())->value,
            execution: $execution,
            counts: $counts,
        );
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
                $targetId = $rec['id'];
                $score = $rec['score'];

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
