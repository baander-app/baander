<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Application;

use App\Activity\Application\Port\ActivityPortInterface;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Recommendation\Application\Command\CancelRecommendationJobCommand;
use App\Recommendation\Application\Command\GenerateRecommendationsCommand;
use App\Recommendation\Application\Command\RequeueRecommendationJobCommand;
use App\Recommendation\Application\CommandHandler\CancelRecommendationJobHandler;
use App\Recommendation\Application\CommandHandler\GenerateRecommendationsHandler;
use App\Recommendation\Application\CommandHandler\RequeueRecommendationJobHandler;
use App\Recommendation\Application\DTO\RecommendationGenerationResult;
use App\Recommendation\Application\Query\ListRecommendationJobsQuery;
use App\Recommendation\Application\QueryHandler\ListRecommendationJobsHandler;
use App\Recommendation\Application\Service\RecommendationJobFinder;
use App\Recommendation\Domain\Model\RecommendationJob;
use App\Recommendation\Domain\Service\CollaborativeFilteringCalculator;
use App\Recommendation\Domain\Service\ContentSimilarityCalculator;
use App\Recommendation\Domain\Service\GenreSimilarityCalculator;
use App\Recommendation\Domain\ValueObject\RecommendationJobStatus;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Tests\Unit\Recommendation\InMemoryRecommendationJobs;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * The rules behind the admin job actions, shared by the API and the app:recommendation:* commands.
 */
final class RecommendationJobUseCasesTest extends TestCase
{
    private InMemoryRecommendationJobs $jobs;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryRecommendationJobs();
    }

    public function testARunOutsideTheWebServerCreatesAJobRecordThatFollowsEachStrategy(): void
    {
        $result = $this->generator()(new GenerateRecommendationsCommand(mode: 'incremental', actor: 'cli'));

        self::assertInstanceOf(RecommendationGenerationResult::class, $result);
        self::assertSame(RecommendationGenerationResult::EXECUTION_SYNC, $result->execution);
        self::assertSame('completed', $result->status);
        self::assertSame(['collaborative' => 0, 'content' => 0, 'genre' => 0], $result->counts);

        $job = $this->jobs->getByPublicId(PublicId::fromString($result->publicId));
        self::assertInstanceOf(RecommendationJob::class, $job);
        self::assertFalse($job->isFull());
        self::assertSame(RecommendationJobStatus::Completed, $job->getStatus());
        self::assertSame('cli', $job->getMetadata()['triggered_by']);
        self::assertSame('incremental', $job->getMetadata()['mode']);
        // Created, started, one save as each strategy starts, completed.
        self::assertSame(['pending', 'in_progress', 'in_progress', 'in_progress', 'in_progress', 'completed'], $this->jobs->savedStatuses);
    }

    public function testARunStopsAtTheNextStrategyAfterAnAdminCancelsItAndKeepsTheCancellation(): void
    {
        $this->jobs->onSave = function (RecommendationJob $job): void {
            if ($job->getCurrentStrategy() === 'content') {
                // Another process cancels the job while the content strategy runs.
                $this->jobs->storedStatus[$job->getId()->toString()] = RecommendationJobStatus::Cancelled;
            }
        };

        $result = $this->generator()(new GenerateRecommendationsCommand(mode: 'full'));

        self::assertInstanceOf(RecommendationGenerationResult::class, $result);
        self::assertSame('cancelled', $result->status);
        self::assertNotContains('completed', $this->jobs->savedStatuses);
        self::assertSame('content', $this->jobs->getByPublicId(PublicId::fromString($result->publicId))?->getCurrentStrategy());
    }

    public function testAFailingRunMarksItsJobFailedAndRethrows(): void
    {
        $activity = $this->createStub(ActivityPortInterface::class);
        $activity->method('getAllListeningHistories')->willThrowException(new \RuntimeException('activity store unavailable'));

        try {
            $this->generator(activity: $activity)(new GenerateRecommendationsCommand(mode: 'full'));
            self::fail('The run should fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('activity store unavailable', $exception->getMessage());
        }

        $job = array_values($this->jobs->jobs)[0];
        self::assertSame(RecommendationJobStatus::Failed, $job->getStatus());
        self::assertSame('activity store unavailable', $job->getFailReason());
    }

    public function testAnUnknownModeIsInvalidInputAndCreatesNoJob(): void
    {
        $this->expectException(InvalidInputException::class);

        try {
            $this->generator()(new GenerateRecommendationsCommand(mode: 'Full'));
        } finally {
            self::assertSame([], $this->jobs->jobs);
        }
    }

    public function testCancellingAPendingJobCancelsItAndRepeatingItChangesNothing(): void
    {
        $job = $this->jobs->create(isFull: true);

        $this->cancel()(new CancelRecommendationJobCommand($job->getPublicId()->toString()));
        self::assertSame(RecommendationJobStatus::Cancelled, $job->getStatus());
        $cancelledAt = $job->getCompletedAt();

        $this->cancel()(new CancelRecommendationJobCommand($job->getPublicId()->toString()));
        self::assertSame(RecommendationJobStatus::Cancelled, $job->getStatus());
        self::assertSame($cancelledAt, $job->getCompletedAt());
    }

    public function testCancellingACompletedOrFailedJobIsAConflict(): void
    {
        $completed = $this->jobs->create(isFull: true);
        $completed->markInProgress(0);
        $completed->markCompleted([]);
        $failed = $this->jobs->create(isFull: true);
        $failed->markFailed('crash');

        foreach ([$completed, $failed] as $job) {
            try {
                $this->cancel()(new CancelRecommendationJobCommand($job->getPublicId()->toString()));
                self::fail('Cancelling a finished job should be a conflict.');
            } catch (ConflictException $exception) {
                self::assertSame(['status' => $job->getStatus()->value], $exception->details);
            }
        }
    }

    public function testCancellingAnUnknownOrMalformedJobIsNotFound(): void
    {
        foreach ([(new PublicId())->toString(), 'not-a-public-id'] as $publicId) {
            try {
                $this->cancel()(new CancelRecommendationJobCommand($publicId));
                self::fail('An unknown job should not be found.');
            } catch (NotFoundException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRequeueCreatesALinkedJobAndRunsIt(): void
    {
        $userId = new Uuid();
        $original = $this->jobs->create(isFull: false, userId: $userId, metadata: ['mode' => 'incremental', 'original_job_public_id' => 'earlier']);
        $original->markFailed('Worker crashed');

        $result = $this->requeue()(new RequeueRecommendationJobCommand($original->getPublicId()->toString(), 'cli'));

        self::assertSame('completed', $result->status);
        self::assertSame('incremental', $result->mode);
        $job = $this->jobs->getByPublicId(PublicId::fromString($result->publicId));
        self::assertInstanceOf(RecommendationJob::class, $job);
        self::assertSame(RecommendationJobStatus::Completed, $job->getStatus());
        self::assertTrue($original->getId()->equals($job->getOriginalJobId()));
        self::assertTrue($userId->equals($job->getUserId()));
        $metadata = $job->getMetadata();
        self::assertSame($original->getPublicId()->toString(), $metadata['requeued_from']);
        self::assertSame($original->getPublicId()->toString(), $metadata['original_job_public_id']);
        self::assertSame(['earlier'], $metadata['requeue_chain']);
        self::assertSame('Worker crashed', $metadata['requeued_reason']);
        self::assertSame('cli', $metadata['requeued_by']);
        self::assertCount(2, $this->jobs->jobs);
    }

    public function testOnlyAFailedOrCancelledJobCanBeRequeued(): void
    {
        $pending = $this->jobs->create(isFull: true);

        $this->expectException(ConflictException::class);
        try {
            $this->requeue()(new RequeueRecommendationJobCommand($pending->getPublicId()->toString(), 'cli'));
        } finally {
            self::assertCount(1, $this->jobs->jobs);
        }
    }

    public function testAGivenJobRunsOnlyWhilePending(): void
    {
        $done = $this->jobs->create(isFull: true);
        $done->markCancelled();

        $this->expectException(ConflictException::class);
        $this->generator()(new GenerateRecommendationsCommand(mode: 'full', jobId: $done->getId()));
    }

    public function testTheListClampsTheLimitAndRejectsAnUnknownStatus(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->jobs->create(isFull: true);
        }
        $list = new ListRecommendationJobsHandler($this->jobs);

        self::assertCount(1, $list(new ListRecommendationJobsQuery(limit: 0)));
        self::assertCount(3, $list(new ListRecommendationJobsQuery(limit: 500, status: 'pending')));
        self::assertSame([], $list(new ListRecommendationJobsQuery(status: 'failed')));

        $this->expectException(InvalidInputException::class);
        $list(new ListRecommendationJobsQuery(status: 'done'));
    }

    private function cancel(): CancelRecommendationJobHandler
    {
        return new CancelRecommendationJobHandler($this->jobs, new RecommendationJobFinder($this->jobs));
    }

    private function requeue(): RequeueRecommendationJobHandler
    {
        $generator = $this->generator();
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            GenerateRecommendationsCommand::class => [$generator],
        ]))]);

        return new RequeueRecommendationJobHandler($this->jobs, new RecommendationJobFinder($this->jobs), $bus);
    }

    private function generator(?ActivityPortInterface $activity = null): GenerateRecommendationsHandler
    {
        $songs = $this->createStub(SongRepositoryInterface::class);
        $songs->method('findAllForRecommendations')->willReturn([]);
        $songs->method('findUpdatedAfter')->willReturn([]);
        if ($activity === null) {
            $activity = $this->createStub(ActivityPortInterface::class);
            $activity->method('getAllListeningHistories')->willReturn([]);
        }

        return new GenerateRecommendationsHandler(
            $this->createStub(MessageBusInterface::class),
            $songs,
            $activity,
            new CollaborativeFilteringCalculator(),
            new ContentSimilarityCalculator(),
            new GenreSimilarityCalculator(),
            $this->jobs,
            // Never started, so the job runs in this process as it does from the console.
            new CpuProcessPool([], 1, new NullLogger()),
            new JsonEncoder(),
            'postgresql://baander.app/unused',
            $this->createStub(SystemSettingsPortInterface::class),
            new NullLogger(),
        );
    }
}
