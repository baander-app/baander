<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Deployment\Pooler;
use Baander\Tooling\Fixtures\Worker\DrillProfile;
use Baander\Tooling\Fixtures\Worker\MediaLoad;
use Baander\Tooling\Fixtures\Worker\MediaLoadVerifier;
use Baander\Tooling\Fixtures\Worker\OperatorConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WorkerMediaLoadTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testDrillProfileDefaultsToTheFixtureAndReadsAPassedProfile(): void
    {
        $default = DrillProfile::resolve(self::ROOT, []);
        self::assertSame([3, 1, null], [$default->videoSlots, $default->audioSlots, $default->device]);
        self::assertSame(trim((string) file_get_contents(self::ROOT . '/' . DrillProfile::DEFAULT_PATH)), $default->json());
        self::assertNull($default->deviceGroup());

        $software = DrillProfile::resolve(self::ROOT, [DrillProfile::ENVIRONMENT => MediaLoad::profilePath('software')]);
        self::assertSame([8, 12, null], [$software->videoSlots, $software->audioSlots, $software->device]);

        $hardware = DrillProfile::resolve(self::ROOT, [DrillProfile::ENVIRONMENT => MediaLoad::profilePath('hardware')]);
        self::assertSame([12, 12, '/dev/dri/renderD128'], [$hardware->videoSlots, $hardware->audioSlots, $hardware->device]);
        // The application escapes slashes; worker readiness compares the environments byte for byte.
        self::assertStringContainsString('"device":"\/dev\/dri\/renderD128"', $hardware->json());
        self::assertSame(485, $hardware->deviceGroup(static fn(string $device): int => $device === '/dev/dri/renderD128' ? 485 : 0));
    }

    public function testDrillProfileRejectsRemuxUnknownFieldsAndAMissingDevice(): void
    {
        $valid = OperatorConfiguration::json(self::ROOT . '/' . MediaLoad::profilePath('hardware'));
        foreach ([
            'remux' => ['capability' => 'remux'] + $valid,
            'reordered' => array_reverse($valid, true),
            'negative' => ['videoEncoderSlots' => -1] + $valid,
            'hardware' => ['hardware' => ['device' => '/dev/dri/renderD128']] + $valid,
        ] as $case => $value) {
            try {
                DrillProfile::fromArray($case, $value);
                self::fail('Accepted ' . $case);
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        DrillProfile::fromArray('hardware', $valid)->deviceGroup(static fn(string $device): false => false);
    }

    public function testLoadProofRequiresObservationAndASourceAndRunsLonger(): void
    {
        $source = (string) realpath(self::ROOT . '/' . MediaLoad::profilePath('software'));
        $environment = ['BAANDER_TEST_OPERATOR_IN_CONTAINER' => '1', 'BAANDER_TEST_TWO_COMMAND' => '1', 'BAANDER_TEST_MEDIA_CONTROL_OBSERVATION' => '1',
            'BAANDER_TEST_MEDIA_LOAD' => 'hardware', 'BAANDER_TEST_MEDIA_LOAD_SOURCE' => $source];
        $configuration = new OperatorConfiguration($environment);
        self::assertSame(
            ['hardware', 12, 25, 'workerMediaLoad'],
            [$configuration->mediaLoad, $configuration->minimumCpus(), $configuration->minimumHostMemoryGiB(), $configuration->budget()],
        );
        foreach ([
            ['BAANDER_TEST_MEDIA_LOAD' => 'gpu'],
            ['BAANDER_TEST_MEDIA_CONTROL_OBSERVATION' => '0'],
            ['BAANDER_TEST_MEDIA_COST' => '1'],
            ['BAANDER_TEST_MEDIA_LOAD_SOURCE' => self::ROOT . '/var/missing-load-source.mkv'],
        ] as $override) {
            try {
                new OperatorConfiguration(array_replace($environment, $override));
                self::fail('Accepted ' . json_encode($override));
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testDatabaseSamplesClassifyBackendsByPoolAndReadPoolerQueues(): void
    {
        $text = implode("\n", [
            'sample 1000.5',
            'backend|baander|172.20.0.9|active|17',
            'backend|baander|172.20.0.9|idle|3',
            'backend|baander_control|172.20.0.9|idle|2',
            'backend|baander|local|active|1',
            'backend|baander|172.20.0.4|idle|1',
            'pools|database,user,cl_active,cl_waiting,sv_active,sv_idle,maxwait',
            'pools|worker_operator_test,baander,20,9,20,0,1',
            'pools|worker_operator_test_control,baander_control,2,0,1,1,0',
            'sample 1001.0',
            'error backends',
            '',
        ]);
        $samples = MediaLoad::databaseSamples($text, '172.20.0.9', 900.0);
        self::assertCount(2, $samples);
        self::assertSame(['application' => 20, 'control' => 2, 'direct' => 2, 'total' => 24], $samples[0]['backends']);
        self::assertEqualsWithDelta(100.5, $samples[0]['monotonic'], .0001);
        self::assertSame('9', $samples[0]['pools']['worker_operator_test']['cl_waiting']);
        self::assertSame(1, $samples[1]['errors']);
    }

    public function testLeaseHelpersAreJudgedAgainstTheirDeadlineOnTheMonotonicClock(): void
    {
        $samples = [['type' => 'sample', 'monotonic' => 100.0, 'uptime' => 110.0]];
        $agents = [
            ['type' => 'leaseAgent', 'action' => 'renew', 'seconds' => .21, 'reapedBy' => 115.0],
            ['type' => 'leaseAgent', 'action' => 'renew', 'seconds' => 1.6, 'reapedBy' => 150.0],
            ['type' => 'leaseAgent', 'action' => 'observe', 'seconds' => .4, 'reapedBy' => 112.0],
        ];
        $leases = MediaLoad::leases(['control' => [...$samples, ...$agents]], 104.0, 120.0);
        self::assertSame(1.5, $leases['deadlineSeconds']);
        self::assertSame(['renew' => 2, 'observe' => 1], $leases['control']['actions']);
        self::assertSame(1, $leases['control']['runHelpers']);
        self::assertSame(.21, $leases['control']['runLifetime']['maxSeconds']);
        self::assertSame([1.6], array_column($leases['control']['overDeadline'], 'seconds'));
    }

    public function testVideoMarginsComeFromSteadyProgressAndTheEncoderNamesThePath(): void
    {
        $stream = static fn(string $codec, float $speed): array => ['job' => $codec, 'session' => 's', 'admittedAt' => 0.0, 'firstOutputAt' => 2.0,
            'completedAt' => 2.0 + 120 / $speed, 'progressSent' => 3, 'progressRejected' => [],
            'launch' => ['argv' => ['ffmpeg', '-i', 'in.mkv', '-c:v', $codec, 'out/manifest.mpd']],
            'timeline' => array_map(static fn(int $second): array => [2.0 + $second, min(120.0, $second * $speed)], range(0, (int) ceil(120 / $speed)))];
        $video = MediaLoad::video(['streams' => [$stream('libx264', .5), $stream('libx264', .75)]], 'software');
        self::assertSame(['libx264', 'software', .5, -.5], [$video['streams'][0]['codec'], $video['streams'][0]['path'], $video['streams'][0]['steadySpeed'], $video['streams'][0]['realTimeMargin']]);
        self::assertSame(['minimum' => .5, 'median' => .625, 'maximum' => .75], $video['steadySpeed']);
        self::assertSame(0, $video['streamsAheadOfRealTime']);
        $hardware = MediaLoad::video(['streams' => [$stream('hevc_vaapi', 1.25)]], 'hardware');
        self::assertSame(['hardware', .25, 1], [$hardware['streams'][0]['path'], $hardware['streams'][0]['realTimeMargin'], $hardware['streamsAheadOfRealTime']]);
    }

    public function testGovernorDecisionsAreSplitByPhaseFromTheControlLog(): void
    {
        $decision = static fn(string $job, string $state, string $allowed = '1080p'): array => ['message' => 'StreamAdmission: allocated stream',
            'context' => ['jobId' => $job, 'governorState' => $state, 'learningSamples' => 50, 'requestedTier' => '1080p', 'allowedTier' => $allowed, 'predictedCost' => 10.0, 'hardwareAccelerated' => false]];
        $client = ['learningSamples' => 50, 'warmUp' => [[]], 'warmUpJobs' => ['w1', 'w2'], 'governorBeforeRun' => ['state' => 'active', 'samples' => 56, 'hardwareSamples' => 0],
            'governorAfterRun' => ['state' => 'active', 'samples' => 64, 'hardwareSamples' => 0]];
        $governor = MediaLoad::governor($client, [
            $decision('w1', 'learning'), $decision('w2', 'learning'), $decision('r1', 'active'), $decision('other', 'active'),
            ['message' => 'StreamAdmission: vetoed stream', 'context' => ['jobId' => 'r2', 'governorState' => 'active']],
            ['message' => 'StreamAdmission: recorded encoder cost sample', 'context' => ['jobId' => 'r1', 'cost' => 9.5, 'encoderCpuSeconds' => 182.0, 'outputSeconds' => 120.0]],
        ], ['r1', 'r2']);
        self::assertSame(['r1'], array_column($governor['runDecisions'], 'jobId'));
        self::assertSame(['learning' => 2], $governor['warmUpDecisionsByState']);
        self::assertSame(['run'], array_column($governor['vetoes'], 'phase'));
        self::assertSame([9.5], array_column($governor['runCostSamples'], 'cost'));
    }

    public function testVerifierPassesACompleteReceiptAndNamesEachViolatedCriterion(): void
    {
        self::assertSame([], MediaLoadVerifier::failures(self::receipt()));
        $total = array_sum(Pooler::defaultPools());
        $cases = [
            'learning decision' => [static fn(array &$r) => $r['governor']['runDecisions'][1]['governorState'] = 'learning', 'while the governor was learning'],
            'downgrade' => [static fn(array &$r) => $r['governor']['runDecisions'][0]['allowedTier'] = '720p', 'was downgraded from 1080p to 720p'],
            'veto' => [static fn(array &$r) => $r['governor']['vetoes'][] = ['jobId' => 'v'], '1 admissions were vetoed'],
            'not learned' => [static fn(array &$r) => $r['governor']['beforeRun']['samples'] = 49, 'had not learned before the run'],
            'incomplete' => [static fn(array &$r) => $r['video']['streams'][0]['completedAt'] = null, 'did not run to completion'],
            'audio' => [static fn(array &$r) => $r['audio']['firstWave']['ok'] = 2, 'first audio wave completed 2 of 3'],
            'occupancy' => [static fn(array &$r) => $r['occupancy']['everySlotSamples'] = 0, 'every video and audio slot busy'],
            'containment' => [static fn(array &$r) => $r['failures']['containment'][] = ['unit' => 'control'], 'media role was contained'],
            'uncertain' => [static fn(array &$r) => $r['failures']['nativeFailures'][] = ['unit' => 'control'], '1 native execution failures'],
            'restart' => [static fn(array &$r) => $r['containment']['unitsAfterRun']['control']['restarts'] = 1, 'stopped or restarted'],
            'oom' => [static fn(array &$r) => $r['oom']['unitOomKills']['execution'] = 1, 'recorded an OOM kill'],
            'backends' => [static fn(array &$r) => $r['database']['peak']['total'] = $total + 1, 'total backends peaked at ' . ($total + 1) . ' above ' . $total],
            'saturation' => [static fn(array &$r) => $r['database']['pools']['application']['saturatedRunSamples'] = 4, 'saturated the application pool in only 4 of 10'],
            'lease' => [static fn(array &$r) => $r['leases']['control']['overDeadline'][] = ['seconds' => 1.6], 'control lease helpers exceeded the 1.5-second deadline'],
            'native answer' => [static fn(array &$r) => $r['native']['connectToAnswer']['maxSeconds'] = 1.2, 'took 1.2 seconds to answer'],
            'relaunch' => [static fn(array &$r) => $r['nativeLaunches']['runJobLaunches'][0] = 2, 'launched other than exactly once'],
            'margin' => [static fn(array &$r) => $r['video']['streams'][1]['steadySpeed'] = null, 'no recorded real-time margin'],
            'path' => [static fn(array &$r) => $r['video']['streams'][1]['path'] = 'hardware', 'encoded on the hardware path in the software pass'],
        ];
        foreach ($cases as $case => [$mutate, $message]) {
            $receipt = self::receipt();
            $mutate($receipt);
            $failures = MediaLoadVerifier::failures($receipt);
            self::assertCount(1, $failures, $case . ': ' . json_encode($failures));
            self::assertStringContainsString($message, $failures[0], $case);
        }
    }

    /** @return array<string,mixed> A passing receipt for two video and three audio slots. */
    private static function receipt(): array
    {
        $stream = static fn(string $job): array => ['job' => $job, 'codec' => 'libx264', 'path' => 'software', 'completedAt' => 200.0, 'steadySpeed' => .55, 'realTimeMargin' => -.45];
        $decision = static fn(string $job): array => ['jobId' => $job, 'governorState' => 'active', 'requestedTier' => '1080p', 'allowedTier' => '1080p'];
        $pools = Pooler::defaultPools();
        return [
            'profile' => ['videoEncoderSlots' => 2, 'audioEncoderSlots' => 3],
            'video' => ['expectedPath' => 'software', 'streams' => [$stream('a'), $stream('b')]],
            'audio' => ['firstWave' => ['count' => 3, 'ok' => 3], 'failed' => 0],
            'occupancy' => ['everySlotSamples' => 4],
            'governor' => ['learningSamples' => 50, 'beforeRun' => ['state' => 'active', 'samples' => 56], 'runDecisions' => [$decision('a'), $decision('b')], 'vetoes' => []],
            'failures' => ['containment' => [], 'nativeFailures' => [], 'otherErrors' => []],
            'containment' => ['unitsAfterRun' => ['control' => ['running' => true, 'restarts' => 0], 'execution' => ['running' => true, 'restarts' => 0]],
                'drainingHeartbeats' => 0, 'leaseEpochs' => ['control' => [1], 'execution' => [1]]],
            'oom' => ['dockerEvents' => 0, 'unitOomKills' => ['control' => 0, 'execution' => 0], 'oomKilledContainers' => []],
            'nativeLaunches' => ['overlappingLaunches' => 0, 'duplicateGenerations' => 0, 'runJobLaunches' => [1, 1]],
            'native' => ['deadlineSeconds' => 1.0, 'errors' => [], 'connectToAnswer' => ['maxSeconds' => .05]],
            'database' => ['samples' => 20, 'runSamples' => 10, 'errors' => 0, 'limits' => [...$pools, 'total' => array_sum($pools)],
                'peak' => ['application' => 20, 'control' => 3, 'direct' => 2, 'total' => 25], 'pools' => ['application' => ['saturatedRunSamples' => 9]]],
            'leases' => ['deadlineSeconds' => 1.5, 'control' => ['runHelpers' => 2, 'overDeadline' => []], 'execution' => ['runHelpers' => 40, 'overDeadline' => []]],
            'killOrder' => ['control' => ['samples' => 10], 'execution' => ['samples' => 10]],
        ];
    }
}
