<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Interface\Console;

use App\Recommendation\Application\Command\CancelRecommendationJobCommand;
use App\Recommendation\Application\Command\GenerateRecommendationsCommand;
use App\Recommendation\Application\Command\RequeueRecommendationJobCommand;
use App\Recommendation\Application\CommandHandler\CancelRecommendationJobHandler;
use App\Recommendation\Application\DTO\RecommendationGenerationResult;
use App\Recommendation\Application\Port\RecommendationInsightsPortInterface;
use App\Recommendation\Application\Query\GetRecommendationJobQuery;
use App\Recommendation\Application\Query\ListRecommendationJobsQuery;
use App\Recommendation\Application\QueryHandler\GetRecommendationJobHandler;
use App\Recommendation\Application\QueryHandler\ListRecommendationJobsHandler;
use App\Recommendation\Application\Service\RecommendationJobFinder;
use App\Recommendation\Interface\Console\RecommendationGenerateCommand;
use App\Recommendation\Interface\Console\RecommendationJobCancelCommand;
use App\Recommendation\Interface\Console\RecommendationJobListCommand;
use App\Recommendation\Interface\Console\RecommendationJobRequeueCommand;
use App\Recommendation\Interface\Console\RecommendationJobShowCommand;
use App\Recommendation\Interface\Console\RecommendationStatsCommand;
use App\Recommendation\Interface\Resource\RecommendationJobResource;
use App\Shared\Application\DTO\InlineJobRun;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Tests\Unit\Recommendation\InMemoryRecommendationJobs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class RecommendationCommandsTest extends TestCase
{
    private InMemoryRecommendationJobs $jobs;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryRecommendationJobs();
    }

    public function testStatsJsonHoldsTheThreeApiPayloads(): void
    {
        $insights = $this->createStub(RecommendationInsightsPortInterface::class);
        $insights->method('getCoverage')->willReturn(['total_tracks' => 4, 'tracks_with_recommendations' => 3, 'tracks_without_recommendations' => 1, 'coverage_percentage' => 75.5]);
        $insights->method('getSourceQuality')->willReturn(['by_source_type' => ['song' => 12], 'avg_confidence_score' => 0.42]);
        $insights->method('getFreshness')->willReturn(['avg_age_seconds' => 3600.5, 'last_generated_at' => '2026-10-08 04:00:00+00']);
        $command = new RecommendationStatsCommand($insights);

        $json = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $json->execute(['--json' => true]));
        self::assertSame(
            [
                'coverage' => $insights->getCoverage(),
                'source_quality' => $insights->getSourceQuality(),
                'freshness' => $insights->getFreshness(),
            ],
            json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );

        $table = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $table->execute([]));
        self::assertStringContainsString('75.5%', $table->getDisplay());
        self::assertMatchesRegularExpression('/song\s+12/', $table->getDisplay());
        self::assertStringContainsString('2026-10-08 04:00:00+00', $table->getDisplay());
    }

    public function testListAndShowPrintTheApiResource(): void
    {
        $failed = $this->jobs->create(isFull: false, metadata: ['mode' => 'incremental']);
        $failed->markFailed('Worker crashed');
        $pending = $this->jobs->create(isFull: true);

        $list = new CommandTester(new RecommendationJobListCommand($this->support()));
        self::assertSame(Command::SUCCESS, $list->execute(['--status' => 'failed', '--json' => true]));
        self::assertSame(
            self::asJson(RecommendationJobResource::collection([$failed])),
            json_decode($list->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );

        $table = new CommandTester(new RecommendationJobListCommand($this->support()));
        self::assertSame(Command::SUCCESS, $table->execute([]));
        self::assertMatchesRegularExpression('/' . $failed->getPublicId() . '\s+failed\s+incremental/', $table->getDisplay());
        self::assertMatchesRegularExpression('/' . $pending->getPublicId() . '\s+pending\s+full/', $table->getDisplay());

        $show = new CommandTester(new RecommendationJobShowCommand($this->support()));
        self::assertSame(Command::SUCCESS, $show->execute(['publicId' => $failed->getPublicId()->toString(), '--json' => true]));
        self::assertSame(self::asJson(RecommendationJobResource::from($failed)), json_decode($show->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testListRejectsAnUnknownStatusOrANonIntegerLimit(): void
    {
        self::assertSame(Command::INVALID, (new CommandTester(new RecommendationJobListCommand($this->support())))->execute(['--status' => 'done']));
        self::assertSame(Command::INVALID, (new CommandTester(new RecommendationJobListCommand($this->support())))->execute(['--limit' => 'ten']));
    }

    public function testShowAndCancelOfAnUnknownJobFail(): void
    {
        $unknown = (new PublicId())->toString();

        $show = new CommandTester(new RecommendationJobShowCommand($this->support()));
        self::assertSame(Command::FAILURE, $show->execute(['publicId' => $unknown]));
        self::assertStringContainsString('Recommendation job not found.', $show->getDisplay());

        self::assertSame(Command::FAILURE, (new CommandTester(new RecommendationJobCancelCommand($this->support())))->execute(['publicId' => $unknown]));
    }

    public function testCancelCancelsAPendingJobAndACompletedOneIsAConflict(): void
    {
        $pending = $this->jobs->create(isFull: true);
        $completed = $this->jobs->create(isFull: true);
        $completed->markInProgress(0);
        $completed->markCompleted([]);

        $cancel = new CommandTester(new RecommendationJobCancelCommand($this->support()));
        self::assertSame(Command::SUCCESS, $cancel->execute(['publicId' => $pending->getPublicId()->toString()]));
        self::assertSame('cancelled', $pending->getStatus()->value);

        $conflict = new CommandTester(new RecommendationJobCancelCommand($this->support()));
        self::assertSame(Command::FAILURE, $conflict->execute(['publicId' => $completed->getPublicId()->toString()]));
        self::assertStringContainsString('can no longer be cancelled', $conflict->getDisplay());
        self::assertSame('completed', $completed->getStatus()->value);
    }

    public function testGenerateRunsInlineAsTheCliAndReportsTheJobAndItsMonitorRecord(): void
    {
        $monitor = $this->createMock(JobMonitorAdministrationInterface::class);
        $monitor->expects(self::once())
            ->method('runInline')
            ->with(self::callback(static fn (object $message): bool => $message instanceof GenerateRecommendationsCommand
                && $message->isIncremental()
                && $message->getActor() === 'cli'
                && $message->getUserId()?->toString() === '0192f3c4-0000-7000-8000-000000000001'))
            ->willReturn(new InlineJobRun('monitorJobId000000001', $this->generationResult('completed')));

        $tester = new CommandTester(new RecommendationGenerateCommand($monitor));
        self::assertSame(Command::SUCCESS, $tester->execute(['--mode' => 'incremental', '--user-id' => '0192f3c4-0000-7000-8000-000000000001']));
        self::assertMatchesRegularExpression('/Recommendation job\s+recommendationJob0001/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Job monitor ID\s+monitorJobId000000001/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/collaborative\s+5/', $tester->getDisplay());
    }

    public function testGenerateFailsWhenTheJobWasCancelledAndRejectsAMalformedUserId(): void
    {
        $monitor = $this->createMock(JobMonitorAdministrationInterface::class);
        $monitor->expects(self::once())->method('runInline')->willReturn(new InlineJobRun('monitorJobId000000001', $this->generationResult('cancelled')));

        $cancelled = new CommandTester(new RecommendationGenerateCommand($monitor));
        self::assertSame(Command::FAILURE, $cancelled->execute([]));
        self::assertStringContainsString('The job was cancelled before it finished', $cancelled->getDisplay());

        $never = $this->createMock(JobMonitorAdministrationInterface::class);
        $never->expects(self::never())->method('runInline');
        self::assertSame(Command::INVALID, (new CommandTester(new RecommendationGenerateCommand($never)))->execute(['--user-id' => 'nobody']));
    }

    public function testRequeueRunsInlineAndAConflictFails(): void
    {
        $monitor = $this->createMock(JobMonitorAdministrationInterface::class);
        $monitor->expects(self::exactly(2))
            ->method('runInline')
            ->willReturnCallback(function (object $message): InlineJobRun {
                self::assertInstanceOf(RequeueRecommendationJobCommand::class, $message);
                self::assertSame('cli', $message->actor);
                if ($message->publicId === 'completedJob000000001') {
                    throw new ConflictException('Only a failed or cancelled recommendation job can be requeued; this one is completed.');
                }

                return new InlineJobRun('monitorJobId000000001', $this->generationResult('completed'));
            });

        $requeued = new CommandTester(new RecommendationJobRequeueCommand($monitor));
        self::assertSame(Command::SUCCESS, $requeued->execute(['publicId' => 'failedJob000000000001']));
        self::assertMatchesRegularExpression('/Recommendation job\s+recommendationJob0001/', $requeued->getDisplay());

        $conflict = new CommandTester(new RecommendationJobRequeueCommand($monitor));
        self::assertSame(Command::FAILURE, $conflict->execute(['publicId' => 'completedJob000000001']));
        self::assertStringContainsString('can be requeued', $conflict->getDisplay());
    }

    private function generationResult(string $status): RecommendationGenerationResult
    {
        return new RecommendationGenerationResult(
            jobId: '0192f3c4-0000-7000-8000-000000000002',
            publicId: 'recommendationJob0001',
            mode: 'incremental',
            status: $status,
            execution: RecommendationGenerationResult::EXECUTION_SYNC,
            counts: ['collaborative' => 5, 'content' => 3, 'genre' => 0],
        );
    }

    /**
     * The payload as JSON carries it, where a whole float such as a 0.0 progress reads back as 0.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function asJson(array $data): array
    {
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function support(): AdminCommandSupport
    {
        $finder = new RecommendationJobFinder($this->jobs);

        return new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ListRecommendationJobsQuery::class => [new ListRecommendationJobsHandler($this->jobs)],
            GetRecommendationJobQuery::class => [new GetRecommendationJobHandler($finder)],
            CancelRecommendationJobCommand::class => [new CancelRecommendationJobHandler($this->jobs, $finder)],
        ]))]));
    }
}
