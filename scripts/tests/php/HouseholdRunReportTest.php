<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Fixtures\Household\{RunContract, RunReport};
use Baander\Tooling\Deployment\Household;
use PHPUnit\Framework\TestCase;

final class HouseholdRunReportTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'baander-household-report-');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    private function contract(): RunContract
    {
        return RunContract::fromArray([
            'version' => 1, 'scenario' => 'original-overlap', 'profile' => 'household', 'imageId' => 'sha256:' . str_repeat('a', 64),
            'operatorMemoryMiB' => 1, 'deploymentOwner' => 'boot', 'workerNamespace' => 'fixture', 'operatorImageId' => 'sha256:' . str_repeat('c', 64), 'workerImageId' => 'sha256:' . str_repeat('d', 64),
            'startedMonotonic' => 100.0, 'sampleIntervalSeconds' => 1.0, 'maxSampleGapSeconds' => 3.0,
            'durationSeconds' => 1800.0, 'maintenanceDeadlineSeconds' => 2400.0,
            'requiredRoles' => ['web', 'observer'], 'configuredLimits' => ['web' => ['memoryMaxBytes' => 1024], 'observer' => ['memoryMaxBytes' => 1024]],
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
            'nativeAudit' => ['complete' => true, 'forbiddenLaunches' => 0], 'unexpectedExits' => [],
            'operatorMeasurements' => ['workload' => $this->operatorInput(['readiness', 'readiness'], 100.0, 1900.0), 'shutdown' => $this->operatorInput(['status', 'recover'], 1900.0, 1910.0)],
            'helperMeasurements' => $this->helperInput($contract),
            'cleanup' => ['normalExit' => true, 'pairRetired' => true, 'dataVolumesRetained' => true, 'fixturesRemoved' => true],
        ];
    }

    /** @return array<string,mixed> */
    private function helperInput(RunContract $contract): array
    {
        $document = $contract->toArray();
        $input = $this->operatorInput(array_keys(Household::helperRoles($document['scenario'] === 'cold-startup')), 100, 1900);
        $images = ['application' => $document['imageId'], 'worker' => $document['workerImageId'], 'operator' => $document['operatorImageId']];
        $input['context']['actionImages'] = array_map(static fn(string $role): string => $images[$role], Household::helperRoles(true) + Household::helperRoles(false));
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
        $context = ['namespace' => 'fixture', 'bootId' => 'boot', 'operatorImage' => 'sha256:' . str_repeat('c', 64), 'workerImage' => 'sha256:' . str_repeat('d', 64), 'memoryMaxBytes' => 1048576];
        $receipts = [];
        foreach ($actions as $index => $action) {
            $receipts[] = ['version' => 1, 'action' => $action, 'namespace' => 'fixture', 'bootId' => 'boot', 'nonce' => str_pad(dechex($index + 1), 32, '0', STR_PAD_LEFT),
                'containerId' => str_repeat('a', 64), 'imageId' => $action === 'ipc-initialize' ? $context['workerImage'] : $context['operatorImage'],
                'status' => 'complete', 'startedMonotonic' => $from + 0.1, 'finishedMonotonic' => $from + 0.2, 'errors' => [],
                'limits' => ['memoryMaxBytes' => 1024, 'memorySwapMaxBytes' => 1024, 'cgroupNamespace' => 'private'], 'cgroup' => ['memoryMaxBytes' => 1024, 'memoryPeakBytes' => 300, 'swapPeakBytes' => 0, 'swapMaxBytes' => 0, 'pidsCurrent' => 1, 'pidsPeak' => null,
                    'identity' => '1:' . $index, 'path' => '/', 'memoryEvents' => ['oom' => 0, 'oom_kill' => 0]],
                'docker' => ['exitCode' => 0, 'running' => false, 'oomKilled' => false]];
        }
        return ['boundaryVerified' => true, 'receipts' => $receipts, 'context' => $context, 'requiredActions' => array_count_values($actions), 'from' => $from, 'until' => $until];
    }

    public function testColdStartRequiresTransientStartupPeaksAndRejectsHelperOom(): void
    {
        $document = $this->contract()->toArray();
        $document['scenario'] = 'cold-startup';
        $contract = RunContract::fromArray($document);
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

    private function samples(?int $missingSequence = null, bool $oom = false, bool $swapGrowth = false, bool $groupSwapGrowth = false): void
    {
        $stream = fopen($this->path, 'wb');
        for ($index = 0; $index <= 1800; ++$index) {
            if ($index === $missingSequence) {
                continue;
            }
            $group = ['path' => '/web', 'roles' => ['web'], 'memoryCurrentBytes' => 100, 'memoryPeakBytes' => 120, 'memoryMaxBytes' => 1024,
                'memoryEvents' => ['oom' => $oom && $index === 1800 ? 1 : 0, 'oom_kill' => 0], 'swapCurrentBytes' => 0, 'swapPeakBytes' => 0, 'swapMaxBytes' => 0, 'pidsCurrent' => 1, 'pidsMax' => 30];
            $sample = ['version' => 1, 'captureWindowSeconds' => 0.01, 'sequence' => $index, 'monotonic' => 100 + $index,
                'host' => ['memTotalBytes' => 4096, 'memAvailableBytes' => 3000, 'swapTotalBytes' => 1000, 'swapFreeBytes' => $swapGrowth ? 1000 - $index / 2 : 1000,
                    'vmstat' => ['pswpin' => 0, 'pswpout' => 0], 'memoryPressure' => ['some' => ['avg10' => 0.0, 'avg60' => 0.0, 'avg300' => 0.0, 'total' => 0], 'full' => ['avg10' => 0.0, 'avg60' => 0.0, 'avg300' => 0.0, 'total' => 0]]],
                'cgroups' => ['1:2' => $group, '1:3' => array_replace($group, ['path' => '/observer', 'roles' => ['observer']])],
                'processes' => ['41:99' => ['pid' => 41, 'threads' => 1, 'native' => false]], 'nativeProcessCount' => 0, 'nativeThreadCount' => 0, 'missing' => []];
            if ($groupSwapGrowth) {
                $sample['host']['swapTotalBytes'] = 2000;
                $sample['host']['swapFreeBytes'] = 1000;
                $sample['cgroups'] = ['1:2' => array_replace($group, [
                    'roles' => ['web', 'observer'], 'swapCurrentBytes' => intdiv($index, 2),
                    'swapPeakBytes' => intdiv($index, 2), 'swapMaxBytes' => 1000,
                ])];
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

    public function testOperatorPeakIsSeparateFromSimultaneousCgroupTotalsAndAllowsTighterCaps(): void
    {
        $input = $this->operatorInput(['readiness', 'readiness'], 100, 1900);
        $input['receipts'][0]['limits']['memoryMaxBytes'] = 512;
        $input['receipts'][0]['limits']['memorySwapMaxBytes'] = 512;
        $input['receipts'][0]['cgroup']['memoryMaxBytes'] = 512;
        $result = RunReport::operatorAudit($input['receipts'], $input['context'], ['readiness' => 2], 100, 1900);
        self::assertTrue($result['complete']);
        self::assertSame(300, $result['peakMemoryBytes']);
        self::assertSame([512, 1024], $result['effectiveMemoryCapsBytes']);
        self::assertFalse($result['pidsPeakAvailableForAll']);
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
