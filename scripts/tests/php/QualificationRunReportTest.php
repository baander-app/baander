<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Fixtures\Qualification\{NativeAudit, RunContract, RunReport};
use Baander\Tooling\Deployment\Preset;
use PHPUnit\Framework\TestCase;

final class QualificationRunReportTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'baander-qualification-report-');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    private function contract(string $scenario = 'original-overlap', int $players = 2): RunContract
    {
        return RunContract::fromArray([
            'version' => 2, 'scenario' => $scenario, 'capability' => 'remux', 'players' => $players,
            'size' => ['webWorkers' => 2, 'databaseSlots' => 4, 'ordinaryConsumers' => 1, 'sharedBuffersMiB' => 128, 'workMemMiB' => 4, 'copySlots' => 2],
            'imageId' => 'sha256:' . str_repeat('a', 64),
            'deploymentOwner' => 'boot', 'workerNamespace' => 'fixture', 'operatorImageId' => 'sha256:' . str_repeat('c', 64), 'workerImageId' => 'sha256:' . str_repeat('d', 64),
            'startedMonotonic' => 100.0, 'sampleIntervalSeconds' => 1.0, 'maxSampleGapSeconds' => 3.0,
            'durationSeconds' => 1800.0, 'maintenanceDeadlineSeconds' => 2400.0,
            'requiredRoles' => ['web', 'observer'], 'configuredLimits' => ['web' => ['memoryMaxBytes' => 'max'], 'observer' => ['memoryMaxBytes' => 1024]],
            'dataset' => ['songs' => 2000, 'movies' => 50, 'episodes' => 250, 'fixtureDigest' => str_repeat('b', 64), 'preparedBeforeRun' => true],
            'schedule' => [['atSeconds' => 0, 'action' => 'two-original-streams-and-maintenance']],
        ]);
    }

    /** @return array<string,mixed> */
    private function evidence(RunContract $contract): array
    {
        $queue = ['completed' => true, 'remaining' => 0, 'failed' => 0, 'lost' => 0, 'retried' => 2, 'maxAttempts' => 2, 'completedAtSeconds' => 1800];
        $playback = ['observedSeconds' => 1800, 'progressSeconds' => 1799.5, 'progressObservations' => 1801, 'stalls' => 0];
        return [
            'contractFingerprint' => $contract->fingerprint(), 'completedMonotonic' => 1900.0,
            'playback' => [['id' => 'one', ...$playback], ['id' => 'two', ...$playback]],
            'queues' => ['scan' => $queue, 'metadata' => $queue],
            'nativeAudit' => ['complete' => true, 'capability' => 'remux', 'forbiddenLaunches' => 0], 'oomKilledContainers' => [], 'unexpectedExits' => [],
            'operatorMeasurements' => ['workload' => $this->operatorInput(['readiness', 'readiness'], 100.0, 1900.0), 'shutdown' => $this->operatorInput(['status', 'recover'], 1900.0, 1910.0)],
            'helperMeasurements' => $this->helperInput($contract),
            'cleanup' => ['normalExit' => true, 'pairRetired' => true, 'dataVolumesRetained' => true, 'fixturesRemoved' => true],
        ];
    }

    /** @return array<string,mixed> */
    private function helperInput(RunContract $contract): array
    {
        $document = $contract->toArray();
        $input = $this->operatorInput(array_keys(Preset::helperRoles($document['scenario'] === 'cold-startup')), 100, 1900);
        $images = ['application' => $document['imageId'], 'worker' => $document['workerImageId'], 'operator' => $document['operatorImageId']];
        $input['context']['actionImages'] = array_map(static fn(string $role): string => $images[$role], Preset::helperRoles(true) + Preset::helperRoles(false));
        foreach ($input['receipts'] as &$receipt) {
            $receipt['imageId'] = $input['context']['actionImages'][$receipt['action']];
        }
        return $input;
    }

    /** @param list<string> $actions
     * @return array<string,mixed>
     */
    private function operatorInput(array $actions, float $from, float $until): array
    {
        $context = ['namespace' => 'fixture', 'bootId' => 'boot', 'operatorImage' => 'sha256:' . str_repeat('c', 64), 'workerImage' => 'sha256:' . str_repeat('d', 64)];
        $receipts = [];
        foreach ($actions as $index => $action) {
            $receipts[] = ['version' => 1, 'action' => $action, 'namespace' => 'fixture', 'bootId' => 'boot', 'nonce' => str_pad(dechex($index + 1), 32, '0', STR_PAD_LEFT),
                'containerId' => str_repeat('a', 64), 'imageId' => $action === 'ipc-initialize' ? $context['workerImage'] : $context['operatorImage'],
                'status' => 'complete', 'startedMonotonic' => $from + 0.1, 'finishedMonotonic' => $from + 0.2, 'errors' => [],
                'cgroup' => ['memoryMaxBytes' => 'max', 'memoryPeakBytes' => 300, 'swapPeakBytes' => 0, 'swapMaxBytes' => 'max', 'pidsCurrent' => 1, 'pidsPeak' => null,
                    'identity' => '1:' . $index, 'path' => '/', 'memoryEvents' => ['oom' => 0, 'oom_kill' => 0]],
                'docker' => ['exitCode' => 0, 'running' => false, 'oomKilled' => false, 'cgroupNamespace' => 'private']];
        }
        return ['boundaryVerified' => true, 'receipts' => $receipts, 'context' => $context, 'requiredActions' => array_count_values($actions), 'from' => $from, 'until' => $until];
    }

    public function testColdStartRequiresTransientStartupPeaksAndRejectsHelperOom(): void
    {
        $contract = $this->contract('cold-startup', 0);
        $this->samples();
        $evidence = $this->evidence($contract);
        $evidence['operatorMeasurements']['workload'] = $this->operatorInput(['prepare', 'ipc-initialize', 'create', 'start', 'readiness'], 100, 1900);
        unset($evidence['helperMeasurements']);
        self::assertContains('Startup helper measurement is incomplete or failed.', RunReport::evaluate($contract, $this->path, $evidence)['failures']);
        $evidence['helperMeasurements'] = $this->helperInput($contract);
        self::assertCount(15, $evidence['helperMeasurements']['receipts']);
        self::assertSame('passed', RunReport::evaluate($contract, $this->path, $evidence)['status']);
        $evidence['helperMeasurements']['receipts'][10]['docker']['oomKilled'] = true;
        $evidence['helperMeasurements']['receipts'][10]['status'] = 'incomplete';
        unset($evidence['helperMeasurements']['receipts'][10]['finishedMonotonic']);
        $result = RunReport::evaluate($contract, $this->path, $evidence);
        self::assertContains('Startup helper measurement is incomplete or failed.', $result['failures']);
        self::assertSame(1, $result['helperMeasurements']['oomEvents']);
        self::assertTrue($result['helperMeasurements']['invocationPeaks'][10]['oomKilled']);
    }

    public function testOverlapRequiresTheMeasuredSourceRenameHelper(): void
    {
        $contract = $this->contract();
        $this->samples();
        $evidence = $this->evidence($contract);
        $evidence['helperMeasurements']['receipts'] = [];
        self::assertContains('Scenario helper measurement is incomplete or failed.', RunReport::evaluate($contract, $this->path, $evidence)['failures']);
    }

    private function samples(?int $missingSequence = null, bool $oom = false, bool $swapGrowth = false, bool $groupSwapGrowth = false, int|string $webMemoryMaxBytes = 'max', bool $oomKill = false): void
    {
        $stream = fopen($this->path, 'wb');
        for ($index = 0; $index <= 1800; ++$index) {
            if ($index === $missingSequence) {
                continue;
            }
            $group = ['path' => '/web', 'roles' => ['web'], 'memoryCurrentBytes' => 100, 'memoryPeakBytes' => 120, 'memoryMaxBytes' => $webMemoryMaxBytes,
                'memoryEvents' => ['oom' => $oom && $index === 1800 ? 1 : 0, 'oom_kill' => $oomKill && $index >= 900 ? 1 : 0], 'swapCurrentBytes' => 0, 'swapPeakBytes' => 0, 'swapMaxBytes' => 'max', 'pidsCurrent' => 1, 'pidsMax' => 'max'];
            $observer = array_replace($group, ['path' => '/observer', 'roles' => ['observer'], 'memoryMaxBytes' => 1024, 'swapMaxBytes' => 0, 'pidsMax' => 32]);
            $sample = ['version' => 1, 'captureWindowSeconds' => 0.01, 'sequence' => $index, 'monotonic' => 100 + $index,
                'host' => ['memTotalBytes' => 4096, 'memAvailableBytes' => 3000, 'swapTotalBytes' => 1000, 'swapFreeBytes' => $swapGrowth ? 1000 - $index / 2 : 1000,
                    'vmstat' => ['pswpin' => 0, 'pswpout' => 0], 'memoryPressure' => ['some' => ['avg10' => 0.0, 'avg60' => 0.0, 'avg300' => 0.0, 'total' => 0], 'full' => ['avg10' => 0.0, 'avg60' => 0.0, 'avg300' => 0.0, 'total' => 0]]],
                'cgroups' => ['1:2' => $group, '1:3' => $observer],
                'processes' => ['41:99' => ['pid' => 41, 'threads' => 1, 'native' => false]], 'nativeProcessCount' => 0, 'nativeThreadCount' => 0, 'missing' => []];
            if ($groupSwapGrowth) {
                $sample['host']['swapTotalBytes'] = 2000;
                $sample['host']['swapFreeBytes'] = 1000;
                $sample['cgroups']['1:2'] = array_replace($group, ['swapCurrentBytes' => intdiv($index, 2), 'swapPeakBytes' => intdiv($index, 2)]);
            }
            fwrite($stream, json_encode($sample, JSON_THROW_ON_ERROR) . "\n");
        }
        fclose($stream);
    }

    public function testCompleteRunPassesWithBoundedRetriesAndSeparateMemoryAccounting(): void
    {
        $contract = $this->contract();
        $this->samples();
        $result = RunReport::evaluate($contract, $this->path, $this->evidence($contract));
        self::assertSame('passed', $result['status']);
        self::assertTrue($result['memoryFitSupported']);
        self::assertSame(4096, $result['measured']['totalHostMemBytes']);
        self::assertSame(200, $result['measured']['peakCgroupCurrentBytes']);
        self::assertSame($contract->toArray()['configuredLimits'], $result['configuredLimits']);
        self::assertArrayNotHasKey('qualified', $result);
    }

    public function testMissingLegacyOperatorEvidenceCannotBecomeACompleteRun(): void
    {
        $contract = $this->contract();
        $this->samples();
        $evidence = $this->evidence($contract);
        unset($evidence['operatorMeasurements']);
        $result = RunReport::evaluate($contract, $this->path, $evidence);
        self::assertSame('failed', $result['status']);
        self::assertFalse($result['memoryFitSupported']);
        self::assertContains('Operator workload measurement is incomplete or failed.', $result['failures']);
    }

    public function testOperatorPeakIsSeparateFromSimultaneousCgroupTotals(): void
    {
        $input = $this->operatorInput(['readiness', 'readiness'], 100, 1900);
        $input['receipts'][1]['cgroup']['memoryPeakBytes'] = 500;
        $result = RunReport::operatorAudit($input['receipts'], $input['context'], ['readiness' => 2], 100, 1900);
        self::assertTrue($result['complete']);
        self::assertSame(500, $result['peakMemoryBytes']);
        self::assertSame([300, 500], array_column($result['invocationPeaks'], 'peakMemoryBytes'));
        self::assertFalse($result['pidsPeakAvailableForAll']);
    }

    public function testOperatorInvocationsMustRunWithoutAMemoryLimit(): void
    {
        foreach ([['memoryMaxBytes' => 1024], ['swapMaxBytes' => 0]] as $limit) {
            $input = $this->operatorInput(['readiness', 'readiness'], 100, 1900);
            $input['receipts'][0]['cgroup'] = array_replace($input['receipts'][0]['cgroup'], $limit);
            $result = RunReport::operatorAudit($input['receipts'], $input['context'], ['readiness' => 2], 100, 1900);
            self::assertFalse($result['complete']);
            self::assertContains('Operator unlimited cgroup, peak or cgroup event evidence is missing or inconsistent.', $result['errors']);
        }
        $input = $this->operatorInput(['readiness', 'readiness'], 100, 1900);
        $input['receipts'][0]['docker']['cgroupNamespace'] = 'host';
        self::assertFalse(RunReport::operatorAudit($input['receipts'], $input['context'], ['readiness' => 2], 100, 1900)['complete']);
    }

    public function testUnknownOperatorMarkersOomAndBadExitFailEvenWithHealthyPlayback(): void
    {
        $input = $this->operatorInput(['readiness', 'readiness'], 100, 1900);
        $input['receipts'][0]['status'] = 'incomplete';
        unset($input['receipts'][0]['finishedMonotonic']);
        self::assertFalse(RunReport::operatorAudit($input['receipts'], $input['context'], ['readiness' => 2], 100, 1900)['complete']);
        $input = $this->operatorInput(['readiness', 'readiness'], 100, 1900);
        $input['receipts'][0]['docker']['oomKilled'] = true;
        $input['receipts'][0]['docker']['exitCode'] = 137;
        $result = RunReport::operatorAudit($input['receipts'], $input['context'], ['readiness' => 2], 100, 1900);
        self::assertFalse($result['complete']);
        self::assertSame(1, $result['oomEvents']);
        self::assertSame(1, $result['failedExits']);
    }

    public function testNotReadyProbeFollowedByReadyProbeIsAnExpectedPoll(): void
    {
        $input = $this->operatorInput(['readiness', 'readiness'], 100, 1900);
        $input['receipts'][0]['docker']['exitCode'] = 1;
        $input['receipts'][1]['startedMonotonic'] = 107.0;
        $input['receipts'][1]['finishedMonotonic'] = 107.8;
        $result = RunReport::operatorAudit($input['receipts'], $input['context'], ['readiness' => 1], 100, 1900);
        self::assertTrue($result['complete'], implode(' ', $result['errors']));
        self::assertSame(0, $result['failedExits']);
        self::assertSame(1, $result['readinessPolls']);
    }

    public function testNotReadyProbeWithoutLaterReadyProbeOrWithOomStillFails(): void
    {
        $input = $this->operatorInput(['readiness', 'readiness'], 100, 1900);
        $input['receipts'][1]['docker']['exitCode'] = 1;
        $input['receipts'][1]['startedMonotonic'] = 107.0;
        $input['receipts'][1]['finishedMonotonic'] = 107.8;
        $result = RunReport::operatorAudit($input['receipts'], $input['context'], ['readiness' => 1], 100, 1900);
        self::assertFalse($result['complete']);
        self::assertSame(1, $result['failedExits']);

        $input = $this->operatorInput(['readiness', 'readiness'], 100, 1900);
        $input['receipts'][0]['docker']['exitCode'] = 1;
        $input['receipts'][0]['docker']['oomKilled'] = true;
        $input['receipts'][1]['startedMonotonic'] = 107.0;
        $input['receipts'][1]['finishedMonotonic'] = 107.8;
        $result = RunReport::operatorAudit($input['receipts'], $input['context'], ['readiness' => 1], 100, 1900);
        self::assertFalse($result['complete']);
        self::assertSame(1, $result['failedExits']);

        $input = $this->operatorInput(['start', 'readiness'], 100, 1900);
        $input['receipts'][0]['docker']['exitCode'] = 1;
        $input['receipts'][1]['startedMonotonic'] = 107.0;
        $input['receipts'][1]['finishedMonotonic'] = 107.8;
        self::assertSame(1, RunReport::operatorAudit($input['receipts'], $input['context'], ['readiness' => 1], 100, 1900)['failedExits']);
    }

    public function testOperatorReceiptsOutsideWindowAreExcludedAndWrongBootCannotPass(): void
    {
        $input = $this->operatorInput(['readiness', 'readiness', 'status'], 100, 1900);
        $input['receipts'][2]['startedMonotonic'] = 98.0;
        $input['receipts'][2]['finishedMonotonic'] = 99.0;
        $result = RunReport::operatorAudit($input['receipts'], $input['context'], ['readiness' => 2], 100, 1900);
        self::assertTrue($result['complete']);
        self::assertSame(1, $result['excludedOutsideInterval']);
        $input['receipts'][0]['bootId'] = 'foreign-boot';
        self::assertFalse(RunReport::operatorAudit($input['receipts'], $input['context'], ['readiness' => 2], 100, 1900)['complete']);
    }

    public function testMissingSequenceFailsEvenWhenTimestampGapIsWithinTolerance(): void
    {
        $contract = $this->contract();
        $this->samples(50);
        $result = RunReport::evaluate($contract, $this->path, $this->evidence($contract));
        self::assertSame('failed', $result['status']);
        self::assertContains('Missing or repeated sample sequence.', $result['failures']);
    }

    public function testMissingNativeAuditAndOomCannotBeReportedAsPassed(): void
    {
        $contract = $this->contract();
        $this->samples(oom: true);
        $evidence = $this->evidence($contract);
        unset($evidence['nativeAudit']);
        $result = RunReport::evaluate($contract, $this->path, $evidence);
        self::assertSame('failed', $result['status']);
        self::assertContains('Native launch audit is incomplete.', $result['failures']);
        self::assertContains('OOM activity was observed.', $result['failures']);
    }

    public function testGrowingSwapDoesNotSupportAMemoryFitClaim(): void
    {
        $contract = $this->contract();
        $this->samples(swapGrowth: true);
        $result = RunReport::evaluate($contract, $this->path, $this->evidence($contract));
        self::assertFalse($result['memoryFitSupported']);
        self::assertGreaterThan(0, $result['measured']['swapGrowthBytes']);
    }

    public function testGrowingDeploymentSwapCannotBeHiddenByOtherHostProcessesReleasingSwap(): void
    {
        $contract = $this->contract();
        $this->samples(groupSwapGrowth: true);
        $result = RunReport::evaluate($contract, $this->path, $this->evidence($contract));
        self::assertSame('passed', $result['status']);
        self::assertFalse($result['memoryFitSupported']);
        self::assertSame(0, $result['measured']['swapGrowthBytes']);
        self::assertSame(900, $result['measured']['cgroupSwapGrowthBytes']);
        self::assertSame(900, $result['measured']['peakCgroupSwapGrowthBytes']);
        self::assertSame(900, $result['measured']['cgroups']['1:2']['swapGrowthBytes']);
    }

    public function testHttpSuccessWithoutPlaybackProgressCannotPass(): void
    {
        $contract = $this->contract();
        $this->samples();
        $evidence = $this->evidence($contract);
        $evidence['playback'] = [['httpStatus' => 200], ['httpStatus' => 200]];
        self::assertSame('failed', RunReport::evaluate($contract, $this->path, $evidence)['status']);
    }

    public function testChangedTrafficScheduleInvalidatesEarlierEvidence(): void
    {
        $contract = $this->contract();
        $this->samples();
        $document = $contract->toArray();
        $document['schedule'][0]['action'] = 'changed-workload';
        $result = RunReport::evaluate(RunContract::fromArray($document), $this->path, $this->evidence($contract));
        self::assertContains('Evidence does not match the declared run contract.', $result['failures']);
    }

    public function testIncompleteFinalSampleAndMissingEvidenceFailClosed(): void
    {
        file_put_contents($this->path, '{"version":1');
        $result = RunReport::evaluate($this->contract(), $this->path, []);
        self::assertSame('failed', $result['status']);
        self::assertFalse($result['memoryFitSupported']);
        self::assertContains('Measurement contains an oversized or incomplete record.', $result['failures']);
    }

    public function testUndrainedMaintenanceCannotPass(): void
    {
        $contract = $this->contract();
        $this->samples();
        $evidence = $this->evidence($contract);
        $evidence['queues']['metadata']['remaining'] = 1;
        self::assertSame('failed', RunReport::evaluate($contract, $this->path, $evidence)['status']);
    }

    public function testMeasurementsMustContinueUntilMaintenanceCompletes(): void
    {
        $contract = $this->contract();
        $this->samples();
        $evidence = $this->evidence($contract);
        $evidence['queues']['metadata']['completedAtSeconds'] = 1900;
        $evidence['completedMonotonic'] = 2000;
        $result = RunReport::evaluate($contract, $this->path, $evidence);
        self::assertSame('failed', $result['status']);
        self::assertContains('Run completion time is missing or inconsistent.', $result['failures']);
    }

    public function testEffectiveMemoryLimitMustMatchDeclaredLimit(): void
    {
        $document = $this->contract()->toArray();
        $document['configuredLimits']['web']['memoryMaxBytes'] = 2048;
        $contract = RunContract::fromArray($document);
        $this->samples();
        $result = RunReport::evaluate($contract, $this->path, $this->evidence($contract));
        self::assertContains('Effective cgroup limits differ from the declared configuration.', $result['failures']);
    }

    public function testAMemoryLimitOnADeploymentServiceFailsTheRun(): void
    {
        $contract = $this->contract();
        $this->samples(webMemoryMaxBytes: 1024);
        $result = RunReport::evaluate($contract, $this->path, $this->evidence($contract));
        self::assertContains('Effective cgroup limits differ from the declared configuration.', $result['failures']);
    }

    public function testRemuxRunWithAnEncoderLaunchFailsTheReport(): void
    {
        $contract = $this->contract();
        $this->samples();
        $journal = tempnam(sys_get_temp_dir(), 'baander-native-audit-');
        self::assertIsString($journal);
        try {
            file_put_contents($journal, json_encode(['event' => 'start', 'at' => 1.0, 'pid' => 10, 'executable' => 'ffmpeg', 'arguments' => ['-i', 'source', '-c:v', 'libx264', '-c:a', 'aac', 'output']], JSON_THROW_ON_ERROR) . "\n");
            $evidence = $this->evidence($contract);
            $evidence['nativeAudit'] = NativeAudit::read($journal, true, true, 'remux');
            $result = RunReport::evaluate($contract, $this->path, $evidence);
            self::assertSame('failed', $result['status']);
            self::assertContains('Prohibited native launch evidence does not pass.', $result['failures']);
            $evidence['nativeAudit'] = NativeAudit::read($journal, true, true, 'transcode');
            self::assertContains('Native launch audit applied another capability than the run contract.', RunReport::evaluate($contract, $this->path, $evidence)['failures']);
        } finally {
            unlink($journal);
        }
    }

    public function testOnePlayerOverlapPassesWithOneProgressingPlayerAndNoSecond(): void
    {
        $contract = $this->contract(players: 1);
        $this->samples();
        $evidence = $this->evidence($contract);
        $evidence['playback'] = array_slice($evidence['playback'], 0, 1);
        $result = RunReport::evaluate($contract, $this->path, $evidence);
        self::assertSame('passed', $result['status']);
        self::assertSame(1, $result['players']);
        $evidence['playback'][0]['stalls'] = 1;
        self::assertContains('Playback progress or interruption evidence does not pass.', RunReport::evaluate($contract, $this->path, $evidence)['failures']);
        $twoPlayers = $this->evidence($contract);
        self::assertContains('Playback progress records do not match the declared player count.', RunReport::evaluate($contract, $this->path, $twoPlayers)['failures']);
    }

    public function testPlaybackScenariosDeclareOneOrTwoPlayersAndOtherRunsNone(): void
    {
        $document = $this->contract()->toArray();
        foreach ([[0, 'original-overlap'], [3, 'recovery'], [1, 'cold-startup']] as [$players, $scenario]) {
            try {
                RunContract::fromArray(array_replace($document, ['players' => $players, 'scenario' => $scenario]));
                self::fail('Accepted ' . $players . ' players for ' . $scenario . '.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('players', $error->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        RunContract::fromArray(array_replace($document, ['capability' => 'unknown']));
    }

    public function testInsufficientMemoryPassesOnAnOomKillOrAStallAndFailsWhenTheRunCompletesCleanly(): void
    {
        $contract = $this->contract('insufficient-memory');
        $this->samples();
        $clean = RunReport::evaluate($contract, $this->path, $this->evidence($contract));
        self::assertSame('failed', $clean['status']);
        self::assertSame(['The deployment completed without an OOM kill or a playback stall.'], $clean['failures']);
        self::assertFalse($clean['memoryFitSupported']);

        $evidence = $this->evidence($contract);
        $evidence['oomKilledContainers'] = ['fixture-web-1'];
        $killed = RunReport::evaluate($contract, $this->path, $evidence);
        self::assertSame('passed', $killed['status']);
        self::assertContains('OOM activity was observed.', $killed['workloadFailures']);
        self::assertFalse($killed['memoryFitSupported']);

        $evidence = $this->evidence($contract);
        $evidence['playback'][1]['stalled'] = true;
        $evidence['playback'][1]['stalls'] = 1;
        self::assertSame(['two'], RunReport::evaluate($contract, $this->path, $evidence)['insufficientMemory']['stalledPlayers']);
        self::assertSame('passed', RunReport::evaluate($contract, $this->path, $evidence)['status']);

        $this->samples(oomKill: true);
        $sampled = RunReport::evaluate($contract, $this->path, $this->evidence($contract));
        self::assertSame('passed', $sampled['status']);
        self::assertSame(1, $sampled['insufficientMemory']['oomKills']);

        $evidence = $this->evidence($contract);
        $evidence['contractFingerprint'] = str_repeat('0', 64);
        self::assertSame('failed', RunReport::evaluate($contract, $this->path, $evidence)['status']);
    }

    public function testRecoveryRequiresTheDeploymentToRecoverButNotUninterruptedPlayback(): void
    {
        $contract = $this->contract('recovery');
        $this->samples();
        $evidence = $this->evidence($contract);
        $evidence['playback'][0]['stalls'] = 1;
        self::assertContains('The deployment did not recover after PostgreSQL restarted.', RunReport::evaluate($contract, $this->path, $evidence)['failures']);
        $evidence['recovery'] = ['recovered' => true];
        self::assertSame('passed', RunReport::evaluate($contract, $this->path, $evidence)['status']);
    }

    public function testUnpreparedDatasetIsRefused(): void
    {
        $document = $this->contract()->toArray();
        $document['dataset']['preparedBeforeRun'] = false;
        $this->expectException(\InvalidArgumentException::class);
        RunContract::fromArray($document);
    }

    public function testContractRejectsShortOverlap(): void
    {
        $document = $this->contract()->toArray();
        $document['durationSeconds'] = 1799;
        $this->expectException(\InvalidArgumentException::class);
        RunContract::fromArray($document);
    }
}
