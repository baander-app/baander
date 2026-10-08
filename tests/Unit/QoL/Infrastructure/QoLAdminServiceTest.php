<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure;

use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\ValueObject\AlgorithmProfile;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\QoL\Infrastructure\QoLAdminService;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Unit\QoL\QoLWorkers;
use PHPUnit\Framework\TestCase;

final class QoLAdminServiceTest extends TestCase
{
    private string $directory;
    private QoLWorkers $workers;
    private QoLAdminService $service;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-qol-admin-' . bin2hex(random_bytes(8));
        $this->workers = new QoLWorkers($this->directory);
        $this->service = new QoLAdminService($this->workers);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testStatusHasOneRowPerWorkerAndTheTotalOfActiveStreams(): void
    {
        $this->workers->governors[0]->allocateStream(new Uuid(), '1080p', 30.0);
        $this->workers->governors[2]->allocateStream(new Uuid(), '720p', 20.0);
        $this->workers->governors[2]->allocateStream(new Uuid(), '720p', 20.0);

        $status = $this->service->getStatus();

        self::assertSame([
            'worker_id' => 0,
            'state' => 'learning',
            'profile' => 'balanced',
            'active_streams' => 1,
            'sample_count' => 0,
            'model_ready' => false,
            'budget_cap' => 0.80,
        ], $status['workers'][0]);
        self::assertSame([0, 1, 2], array_column($status['workers'], 'worker_id'));
        self::assertSame([1, 0, 2], array_column($status['workers'], 'active_streams'));
        self::assertSame(['active_streams' => 3], $status['total']);
        self::assertSame([], $status['missing_workers']);
        self::assertSame([], $status['worker_errors']);
    }

    public function testStatusNamesWorkersThatDidNotAnswer(): void
    {
        $this->workers->missing = [1];

        $status = $this->service->getStatus();

        self::assertSame([0, 2], array_column($status['workers'], 'worker_id'));
        self::assertSame([1], $status['missing_workers']);
    }

    public function testStreamsListEachWorkersAllocationsWithRoundedCostAndATotal(): void
    {
        $this->workers->governors[1]->allocateStream(Uuid::fromString('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'), '4K', 55.567);
        $this->workers->governors[2]->allocateStream(Uuid::fromString('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'), '720p', 20.0);

        $streams = $this->service->getActiveStreams();

        self::assertSame(['worker_id' => 0, 'active_streams' => 0, 'streams' => []], $streams['workers'][0]);
        self::assertSame([
            'worker_id' => 1,
            'active_streams' => 1,
            'streams' => [['job_id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'quality_tier' => '4K', 'predicted_cost' => 55.57]],
        ], $streams['workers'][1]);
        self::assertSame(['active_streams' => 2, 'predicted_cost' => 75.57], $streams['total']);
    }

    public function testSettingTheProfileReachesEveryWorkerAndIsSaved(): void
    {
        $report = $this->service->setProfile('aggressive');

        self::assertSame(['aggressive', 'aggressive', 'aggressive'], array_column($report['workers'], 'profile'));
        foreach ($this->workers->governors as $governor) {
            self::assertSame(AlgorithmProfile::Aggressive, $governor->getProfile());
        }
        self::assertSame(['profile' => 'aggressive'], $this->savedProfile());
    }

    public function testSettingTheSameProfileAgainSucceeds(): void
    {
        $this->service->setProfile('conservative');

        $report = $this->service->setProfile('conservative');

        self::assertSame(['conservative', 'conservative', 'conservative'], array_column($report['workers'], 'profile'));
        self::assertSame([], $report['worker_errors']);
    }

    public function testAnUnknownProfileIsInvalidInputAndChangesNoWorker(): void
    {
        try {
            $this->service->setProfile('turbo');
            self::fail('An unknown profile was accepted.');
        } catch (InvalidInputException $exception) {
            self::assertSame('Unknown profile "turbo". Must be one of: conservative, balanced, aggressive.', $exception->getMessage());
            self::assertSame(['profile' => ['Must be one of: conservative, balanced, aggressive.']], $exception->details);
        }

        self::assertSame([], $this->workers->calls);
        self::assertSame(['balanced', 'balanced', 'balanced'], array_column($this->service->getStatus()['workers'], 'profile'));
        self::assertFileDoesNotExist($this->directory . '/algorithm_profile.json');
    }

    public function testAMissingProfileIsInvalidInput(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('A profile is required.');

        $this->service->setProfile('');
    }

    public function testAProfileChangeWithAMissingWorkerIsReportedAsPartial(): void
    {
        $this->workers->missing = [2];

        $report = $this->service->setProfile('aggressive');

        self::assertSame([0, 1], array_column($report['workers'], 'worker_id'));
        self::assertSame([2], $report['missing_workers']);
    }

    public function testResetReturnsEveryWorkerToLearningClearsStreamsAndSavesTheReset(): void
    {
        foreach ($this->workers->governors as $governor) {
            $this->train($governor->getModel());
            $governor->activate();
            $governor->allocateStream(new Uuid(), '1080p', 30.0);
        }

        $report = $this->service->resetLearning();

        self::assertSame(['learning', 'learning', 'learning'], array_column($report['workers'], 'state'));
        self::assertSame([0, 0, 0], array_column($report['workers'], 'sample_count'));
        self::assertSame(['active_streams' => 0], $report['total']);
        foreach ($this->workers->governors as $governor) {
            self::assertSame(GovernorState::Learning, $governor->getState());
        }
        $saved = json_decode((string) file_get_contents($this->directory . '/governor_state.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('learning', $saved['governor']['state']);
        self::assertSame([], $saved['governor']['model']['samples']);
    }

    public function testNoRunningServerIsReported(): void
    {
        $port = $this->createStub(ServerControlPortInterface::class);
        $port->method('execute')->willThrowException(new ServerNotRunningException());

        $this->expectException(ServerNotRunningException::class);
        $this->expectExceptionMessage('no web server is running in this container');

        (new QoLAdminService($port))->getStatus();
    }

    /** @return array<string, mixed> */
    private function savedProfile(): array
    {
        return json_decode((string) file_get_contents($this->directory . '/algorithm_profile.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function train(LearningModel $model): void
    {
        for ($i = 0; $i < LearningModel::MIN_SAMPLES; $i++) {
            $model->addSample(new UtilizationSample(50.0, 0.0, 30.0, 1080, 'h264', false, 5_000_000, '1080p', 1));
        }
    }
}
