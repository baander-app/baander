<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\{ApplicationLayout, ToolingConfiguration};
use Baander\Tooling\Deployment\PresetStateStore;
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Filesystem\PrivateDirectory;
use Baander\Tooling\Process\{EngineExecutor, ExecutionContext, ProcessResult, ProcessSpec, CommandExecutor, CompletionPolicy};
use Baander\Tooling\Fixtures\Qualification\{Observer, Qualification};
use PHPUnit\Framework\TestCase;

final class QualificationTest extends TestCase
{
    private const array SIZE = ['webWorkers' => 2, 'databaseSlots' => 4, 'ordinaryConsumers' => 1, 'sharedBuffersMiB' => 128, 'workMemMiB' => 4, 'copySlots' => 2];

    public function testConfigurationDeclaresWholeHostAndLongWorkloadBeforePreparation(): void
    {
        $configuration = $this->configuration();
        $validated = Qualification::validateConfiguration($configuration);
        self::assertSame(7200, $validated['fixtureTimeoutSeconds']);
        self::assertSame(7200, $validated['maintenanceDeadlineSeconds']);
        self::assertSame(1800, $validated['durationSeconds']);
        $this->expectException(\InvalidArgumentException::class);
        Qualification::validateConfiguration(array_replace($configuration, ['durationSeconds' => 60]));
    }

    public function testColdStartContractAllowsItsSamplingGapWithoutRequiringThirtyMinutePlayback(): void
    {
        $this->withPreparedQualification(function (Qualification $qualification, array $state): void {
            $contract = new \ReflectionMethod(Qualification::class, 'contract')->invoke($qualification, $state, 'cold-startup', 10.0, 0);
            $declaration = $contract->toArray();
            self::assertGreaterThan($declaration['maxSampleGapSeconds'], $declaration['durationSeconds']);
            self::assertLessThan(1800, $declaration['durationSeconds']);
            self::assertFalse($contract->requiresPlayback());
        });
    }

    public function testObserverStartFailureProducesAFailureReceiptAndClearsActiveRun(): void
    {
        $this->withPreparedQualification(function (Qualification $qualification, array $state, string $directory): void {
            try {
                $qualification->start();
                self::fail('Observer startup unexpectedly succeeded.');
            } catch (\RuntimeException $error) {
                self::assertSame('Simulated observer startup failure.', $error->getMessage());
            }
            $saved = AtomicFile::readJson($directory . '/qualification.json');
            self::assertSame('start-failed', $saved['phase']);
            self::assertArrayNotHasKey('activeRun', $saved);
            $receipt = AtomicFile::readJson($directory . '/runs/cold-startup/receipt.json');
            self::assertSame('Simulated observer startup failure.', $receipt['failure']);
        });
    }

    public function testContractFailureProducesAFailureReceiptBeforeAnyObserverIsCreated(): void
    {
        $this->withPreparedQualification(function (Qualification $qualification, array $state, string $directory): void {
            $state['images']['app'] = 'baander/fixture:latest';
            AtomicFile::writeJson($directory . '/qualification.json', $state);
            try {
                $qualification->start();
                self::fail('A mutable run image unexpectedly started.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Run image must be immutable', $error->getMessage());
            }
            self::assertSame('start-failed', AtomicFile::readJson($directory . '/qualification.json')['phase']);
            self::assertFileExists($directory . '/runs/cold-startup/receipt.json');
            self::assertFileDoesNotExist($directory . '/runs/cold-startup/observer-resources.json');
        });
    }

    public function testScenarioObserverFailureAlsoRetainsItsFailureReceipt(): void
    {
        $this->withPreparedQualification(function (Qualification $qualification, array $state, string $directory): void {
            $state['phase'] = 'running';
            $state['provision'] = ['libraries' => []];
            $state['playbackSlots'] = [];
            AtomicFile::writeJson($directory . '/qualification.json', $state);
            $result = $qualification->scenario('original-overlap');
            self::assertSame('Simulated observer startup failure.', $result['failure']);
            $saved = AtomicFile::readJson($directory . '/qualification.json');
            self::assertSame('scenario-failed', $saved['phase']);
            self::assertArrayNotHasKey('activeRun', $saved);
            self::assertSame('Simulated observer startup failure.', AtomicFile::readJson($directory . '/runs/original-overlap-1/receipt.json')['failure']);
        });
    }

    public function testHostWithSwapRefusesStartAndScenarioBeforeAnyDeploymentIsCreated(): void
    {
        $calls = [];
        $this->withPreparedQualification(function (Qualification $qualification, array $state, string $directory): void {
            try {
                $qualification->start();
                self::fail('A host with swap unexpectedly started.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('swap', $error->getMessage());
            }
            self::assertSame('prepared', AtomicFile::readJson($directory . '/qualification.json')['phase']);
            self::assertDirectoryDoesNotExist($directory . '/runs');
            $state['phase'] = 'running';
            $state['provision'] = ['libraries' => []];
            $state['playbackSlots'] = [];
            AtomicFile::writeJson($directory . '/qualification.json', $state);
            try {
                $qualification->scenario('original-overlap', 1, 1);
                self::fail('A host with swap unexpectedly ran a scenario.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('swap', $error->getMessage());
            }
            self::assertSame('running', AtomicFile::readJson($directory . '/qualification.json')['phase']);
            self::assertDirectoryDoesNotExist($directory . '/runs');
        }, function (CommandExecutor&\PHPUnit\Framework\MockObject\Stub $executor) use (&$calls): void {
            $executor->method('run')->willReturnCallback(self::hostMemoryDocker(1024 ** 3, $calls));
        });
        $created = array_values(array_filter($calls, static fn(ProcessSpec $call): bool => ($call->argv[3] ?? null) === 'create'));
        self::assertCount(2, $created);
        foreach ($created as $call) {
            self::assertContains('type=bind,src=/proc,dst=/host/proc,readonly', $call->argv);
        }
        self::assertFalse(array_any($calls, static fn(ProcessSpec $call): bool => in_array('compose', $call->argv, true) || in_array('volume', $call->argv, true)));
    }

    public function testHostMemoryCheckNeedsAMeasuredHostWithinTheConstraintAndNoSwap(): void
    {
        Qualification::assertHostMemory(['memTotalBytes' => 4 * 1024 ** 3, 'swapTotalBytes' => 0], 4 * 1024 ** 3);
        $refused = 0;
        foreach ([['memTotalBytes' => 4 * 1024 ** 3 + 1, 'swapTotalBytes' => 0], ['memTotalBytes' => null, 'swapTotalBytes' => 0],
            ['memTotalBytes' => 4 * 1024 ** 3, 'swapTotalBytes' => 4096], ['memTotalBytes' => 4 * 1024 ** 3, 'swapTotalBytes' => null]] as $memory) {
            try {
                Qualification::assertHostMemory($memory, 4 * 1024 ** 3);
                self::fail('Accepted an unsuitable host.');
            } catch (\RuntimeException) {
                ++$refused;
            }
        }
        self::assertSame(4, $refused);
    }

    public function testDeploymentReplacementFailsWhenTheNewBootKeepsTheOldFingerprint(): void
    {
        $before = ['profileFingerprint' => str_repeat('a', 64), 'composeHash' => str_repeat('b', 64), 'size' => self::SIZE, 'workerBootId' => str_repeat('c', 32)];
        $after = ['profileFingerprint' => str_repeat('d', 64), 'composeHash' => str_repeat('e', 64), 'size' => array_replace(self::SIZE, ['webWorkers' => 1]), 'workerBootId' => str_repeat('f', 32)];
        $receipt = ['failure' => null, 'before' => $before, 'after' => $after, 'serving' => ['workersReady' => true, 'healthy' => true, 'catalogIntact' => true]];
        self::assertSame('passed', Qualification::replacementCheck($receipt)['status']);
        $receipt['after']['profileFingerprint'] = $before['profileFingerprint'];
        $check = Qualification::replacementCheck($receipt);
        self::assertSame('failed', $check['status']);
        self::assertSame(['The new boot has the same deployment fingerprint as the old one.'], $check['failures']);
        unset($receipt['after'], $receipt['serving']);
        $receipt['failure'] = 'Simulated start failure.';
        self::assertContains('Simulated start failure.', Qualification::replacementCheck($receipt)['failures']);
    }

    public function testDeploymentReplacementNeedsADifferentDeclaredSizeAndNoPlayers(): void
    {
        $configuration = $this->configuration();
        $configuration['replacementSize'] = array_replace(self::SIZE, ['webWorkers' => 1]);
        self::assertSame(1, Qualification::validateConfiguration($configuration)['replacementSize']['webWorkers']);
        try {
            Qualification::validateConfiguration(array_replace($configuration, ['replacementSize' => self::SIZE]));
            self::fail('Accepted an unchanged replacement size.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('differ', $error->getMessage());
        }
        $this->withPreparedQualification(function (Qualification $qualification): void {
            try {
                $qualification->scenario('deployment-replacement');
                self::fail('Replaced without a declared size.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('replacementSize', $error->getMessage());
            }
            $this->expectException(\InvalidArgumentException::class);
            $qualification->scenario('deployment-replacement', 1, 1);
        });
    }

    public function testHealthyAnswerNeedsHttpOkAndNoUnhealthyCheck(): void
    {
        $body = ['status' => 'healthy', 'checks' => [['component' => 'postgresql', 'status' => 'healthy'], ['component' => 'memory', 'status' => 'not_available']]];
        self::assertTrue(Qualification::healthy(['httpStatus' => 200, 'body' => $body]));
        self::assertFalse(Qualification::healthy(['httpStatus' => 503, 'body' => $body]));
        $body['checks'][] = ['component' => 'worker', 'status' => 'unhealthy'];
        self::assertFalse(Qualification::healthy(['httpStatus' => 200, 'body' => $body]));
        self::assertFalse(Qualification::healthy(['httpStatus' => null, 'body' => null]));
    }

    public function testOnlyOomEventsOfThisDeploymentCount(): void
    {
        $event = static fn(array $attributes, string $action = 'oom'): string => json_encode(['Action' => $action, 'Actor' => ['ID' => str_repeat('a', 64), 'Attributes' => $attributes]], JSON_THROW_ON_ERROR);
        $events = implode("\n", [
            $event(['name' => 'qualification-web-1', 'org.baander.preset.owner' => 'owner']),
            $event(['name' => 'qualification-execution', 'app.baander.worker.namespace' => 'qualification']),
            $event(['name' => 'qualification-web-1', 'org.baander.preset.owner' => 'owner'], 'die'),
            $event(['name' => 'unrelated', 'org.baander.preset.owner' => 'other']),
        ]) . "\n";
        self::assertSame(['qualification-web-1', 'qualification-execution'], Qualification::ownedOomEvents($events, 'qualification', 'owner'));
        self::assertSame([], Qualification::ownedOomEvents('', 'qualification', 'owner'));
    }

    public function testSeedReuseAcceptsUnchangedImportedSeedAndRejectsUnsafeSources(): void
    {
        $configuration = Qualification::validateConfiguration($this->configuration());
        $target = ['daemonId' => 'fixture', 'endpoint' => 'unix:///run/baander.app.sock'];
        $seedConfiguration = $configuration;
        $seedConfiguration['name'] = 'seed-qualification';
        $seed = ['version' => 1, 'phase' => 'running', 'configuration' => $seedConfiguration, 'target' => $target, 'runs' => ['cold-startup' => 'receipt']];
        Qualification::validateSeed($seed, $configuration, $target);
        $tuned = $configuration;
        $tuned['deployment']['resources']['redisMaxmemoryMiB'] = 256;
        $tuned['host']['constraintBytes'] = 8 * 1024 ** 3;
        Qualification::validateSeed($seed, $tuned, $target);
        self::assertSame(256, $tuned['deployment']['resources']['redisMaxmemoryMiB']);
        foreach ([['borrowedFrom' => []], ['changedSourcePath' => 'renamed.flac'], ['activeRun' => 'cold-startup'], ['phase' => 'cleaned'], ['target' => ['daemonId' => 'foreign', 'endpoint' => $target['endpoint']]]] as $change) {
            try {
                Qualification::validateSeed(array_replace($seed, $change), $configuration, $target);
                self::fail('Unsafe fixture seed accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Fixture seed', $error->getMessage());
            }
        }
    }

    public function testSeedAndChildPathsMustBeSeparateAndNotNested(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-paths-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory . '/seed', 0700);
        mkdir($directory . '/child', 0700);
        try {
            self::assertSame($directory . '/seed', Qualification::sourceDirectory($directory . '/seed', $directory . '/child'));
            $this->expectException(\InvalidArgumentException::class);
            Qualification::sourceDirectory($directory . '/seed', $directory);
        } finally {
            Collector::remove($directory);
        }
    }

    public function testParentImageCleanupWaitsForMatchingChildCleanupReceipt(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-borrowers-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory . '/child', 0700);
        $state = ['borrowers' => [$directory . '/child' => ['borrowerId' => 'child-identity']]];
        $child = ['phase' => 'prepared', 'borrowedFrom' => ['stateDirectory' => $directory . '/seed', 'borrowerId' => 'child-identity']];
        try {
            AtomicFile::writeJson($directory . '/child/qualification.json', $child);
            try {
                Qualification::assertBorrowersCleaned($state, $directory . '/seed');
                self::fail('Active child allowed parent image cleanup.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Retain shared fixture images', $error->getMessage());
            }
            $child['phase'] = 'cleaned';
            AtomicFile::writeJson($directory . '/child/qualification.json', $child);
            Qualification::assertBorrowersCleaned($state, $directory . '/seed');
        } finally {
            Collector::remove($directory);
        }
    }

    public function testPreparedMediaCopyVerifiesHashesAndDoesNotShareMutableFiles(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-copy-prepared-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory . '/source', 0700);
        mkdir($directory . '/destination', 0700);
        file_put_contents($directory . '/source/song.flac', 'prepared source bytes');
        file_put_contents($directory . '/source/song.lrc', 'prepared sidecar');
        $manifest = ['files' => [['path' => 'song.flac', 'size' => filesize($directory . '/source/song.flac'), 'hash' => hash_file('xxh3', $directory . '/source/song.flac')]]];
        $executor = new EngineExecutor(ExecutionContext::bounded(5, getenv()));
        try {
            $result = $executor->run(new ProcessSpec([PHP_BINARY, '-r', Qualification::copyProgram(), $directory . '/source', $directory . '/destination'], stdin: json_encode($manifest, JSON_THROW_ON_ERROR)));
            self::assertSame(1, json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR)['verifiedMedia']);
            self::assertSame('prepared sidecar', file_get_contents($directory . '/destination/song.lrc'));
            file_put_contents($directory . '/destination/song.flac', 'changed child bytes');
            self::assertSame('prepared source bytes', file_get_contents($directory . '/source/song.flac'));
            unlink($directory . '/destination/song.flac');
            unlink($directory . '/destination/song.lrc');
            file_put_contents($directory . '/source/song.flac', 'changed seed bytes');
            $failure = $executor->run(new ProcessSpec([PHP_BINARY, '-r', Qualification::copyProgram(), $directory . '/source', $directory . '/destination'], stdin: json_encode($manifest, JSON_THROW_ON_ERROR)), new CompletionPolicy(null));
            self::assertNotSame(0, $failure->exitCode);
            self::assertSame(['.', '..'], scandir($directory . '/destination'));
        } finally {
            $executor->close();
            Collector::remove($directory);
        }
    }

    public function testMetadataCoverageRequiresFreshMatchedRequestsNotOldEnrichmentEvidence(): void
    {
        $dataset = ['albums' => ['album' => 'album-id'], 'files' => [['kind' => 'song', 'metadata' => ['id' => 'song-id']]]];
        $path = tempnam(sys_get_temp_dir(), 'baander.app-metadata-proof-');
        $lines = [json_encode(['at' => 9, 'outcome' => 'matched', 'identity' => 'song-id'], JSON_THROW_ON_ERROR),
            json_encode(['at' => 11, 'outcome' => 'matched', 'identity' => 'album-id'], JSON_THROW_ON_ERROR)];
        try {
            file_put_contents($path, implode("\n", $lines) . "\n");
            self::assertFalse(Qualification::metadataCoverage($path, $dataset, 10, 20)['complete']);
            file_put_contents($path, json_encode(['at' => 12, 'outcome' => 'matched', 'identity' => 'song-id'], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
            self::assertTrue(Qualification::metadataCoverage($path, $dataset, 10, 20)['complete']);
            file_put_contents($path, json_encode(['at' => 13, 'outcome' => 'unmatched', 'identity' => null], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
            self::assertFalse(Qualification::metadataCoverage($path, $dataset, 10, 20)['complete']);
        } finally {
            unlink($path);
        }
    }

    public function testDatasetIdentityUsesRecordedSourcesInsteadOfPreparationTime(): void
    {
        $dataset = ['preparation' => ['startedAt' => 1], 'files' => [
            ['path' => 'first.flac', 'hash' => 'abc', 'size' => 123, 'duration' => 1860.0],
            ['path' => 'second.mp4', 'hash' => 'def', 'size' => 456, 'duration' => 30.0],
        ]];
        $identity = Qualification::datasetDigest($dataset);
        $persisted = json_decode(json_encode($dataset, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $persisted['files'] = array_map(static function (array $file): array {
            ksort($file);
            return $file;
        }, $persisted['files']);
        self::assertSame($identity, Qualification::datasetDigest($persisted));
        $dataset['preparation']['startedAt'] = 2;
        $dataset['files'] = array_reverse($dataset['files']);
        self::assertSame($identity, Qualification::datasetDigest($dataset));
        $dataset['files'][0]['hash'] = 'changed';
        self::assertNotSame($identity, Qualification::datasetDigest($dataset));
    }

    public function testMaintenanceNeedsCompleteCatalogAndKnownEmptyQueues(): void
    {
        $snapshot = $this->snapshot();
        $queues = ['async' => $this->emptyQueue(), 'scheduler' => $this->emptyQueue()];
        self::assertTrue(Qualification::maintenanceEvidence($snapshot, $queues, 45)['metadata']['completed']);
        $queues['async']['state'] = 'uninitialized';
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['metadata']['completed']);
        $queues['async'] = $this->emptyQueue();
        $snapshot['libraries']['music']['songsWithMbid'] = 1999;
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['metadata']['completed']);
        $snapshot['libraries']['movies']['missingCatalogFiles'] = 1;
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['scan']['completed']);
    }

    public function testNormalVideoIngestionDoesNotRequireUnintegratedMovieEnrichment(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['libraries']['movies']['moviesWithTmdbId'] = 0;
        $snapshot['libraries']['episodes']['moviesWithTmdbId'] = 0;
        $queues = ['async' => $this->emptyQueue(), 'scheduler' => $this->emptyQueue()];
        $evidence = Qualification::maintenanceEvidence($snapshot, $queues, 45);
        self::assertTrue($evidence['metadata']['completed']);
        self::assertStringContainsString('unexercised', $evidence['metadata']['movieMetadataEnrichment']);
        $snapshot['libraries']['movies']['videos'] = 49;
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['scan']['completed']);
    }

    public function testScannedRenameCanLeaveOneUnavailableHistoricalIndexRow(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['libraries']['music']['indexedFiles'] = 2001;
        $snapshot['libraries']['music']['indexedUnavailableFiles'] = 1;
        $queues = ['async' => $this->emptyQueue(), 'scheduler' => $this->emptyQueue()];
        self::assertTrue(Qualification::maintenanceEvidence($snapshot, $queues, 45)['scan']['completed']);
        $snapshot['libraries']['music']['indexedAvailableFiles'] = 1999;
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['scan']['completed']);
    }

    public function testRenamePlanAvoidsPlaybackSourcesAndPreservesTheExpectedContentHash(): void
    {
        $files = [];
        for ($index = 0; $index < 2000; ++$index) {
            $files[] = ['kind' => 'song', 'path' => 'run/music/album/song-' . $index . '.flac', 'hash' => 'digest-' . $index];
        }
        $plan = Qualification::renamePlan(['files' => $files], 'original-overlap', 1);
        self::assertSame('run/music/album/song-1999.flac', $plan['source']);
        self::assertSame('digest-1999', $plan['expectedHash']);
        self::assertSame('run/music/album/song-1999-original-overlap-1.lrc', $plan['targetSidecar']);
        self::assertNotEmpty(token_get_all('<?php ' . Qualification::renameProgram(), TOKEN_PARSE));
    }

    public function testRenameMovesMediaAndSidecarWithoutChangingContent(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-rename-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory . '/song.flac', 'fixture bytes');
        file_put_contents($directory . '/song.lrc', 'fixture lyrics');
        $executor = new EngineExecutor(ExecutionContext::bounded(5, getenv()));
        $plan = ['source' => 'song.flac', 'target' => 'moved.flac', 'sourceSidecar' => 'song.lrc', 'targetSidecar' => 'moved.lrc', 'expectedHash' => hash_file('xxh3', $directory . '/song.flac')];
        try {
            $result = $executor->run(new ProcessSpec([PHP_BINARY, '-r', Qualification::renameProgram(), $directory], stdin: json_encode($plan, JSON_THROW_ON_ERROR)));
            $receipt = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($plan['expectedHash'], $receipt['afterHash']);
            self::assertFileDoesNotExist($directory . '/song.flac');
            self::assertSame('fixture lyrics', file_get_contents($directory . '/moved.lrc'));
        } finally {
            $executor->close();
            Collector::remove($directory);
        }
    }

    public function testRenameContainerRunsWithoutAContainerLimit(): void
    {
        $created = null;
        $this->withPreparedQualification(function (Qualification $qualification, array $state, string $directory) use (&$created): void {
            $store = new PresetStateStore($directory . '/deployment');
            $deployment = $store->load();
            $deployment['composeFile'] = $directory . '/compose.json';
            $store->save($deployment);
            $files = [];
            for ($index = 0; $index < 2000; ++$index) {
                $files[] = ['kind' => 'song', 'path' => 'run/music/album/song-' . $index . '.flac', 'hash' => 'digest-' . $index];
            }
            AtomicFile::writeJson($directory . '/dataset-manifest.json', ['files' => $files]);
            try {
                new \ReflectionMethod(Qualification::class, 'renameSource')->invokeArgs($qualification, [&$state, 'original-overlap', 1, $directory . '/run']);
            } catch (\RuntimeException) {
            }
            self::assertInstanceOf(ProcessSpec::class, $created);
            foreach (['--memory', '--memory-swap', '--pids-limit', '--cpus', '--cpuset-cpus'] as $limit) {
                self::assertNotContains($limit, $created->argv);
            }
            self::assertContains('--cgroupns', $created->argv);
            self::assertContains('memory_limit=64M', $created->argv);
        }, function (CommandExecutor&\PHPUnit\Framework\MockObject\Stub $executor) use (&$created): void {
            $executor->method('run')->willReturnCallback(static function (ProcessSpec $command) use (&$created): \Baander\Tooling\Process\ProcessResult {
                $output = '';
                if (in_array('info', $command->argv, true)) {
                    $output = '{"ID":"fixture-daemon"}';
                } elseif (in_array('psql', $command->argv, true)) {
                    $output = '[{"publicId":"fixture-song","hash":"digest-1999"}]';
                } elseif (in_array('create', $command->argv, true)) {
                    $created = $command;
                    throw new \RuntimeException('Captured rename container before launch.');
                }
                return new \Baander\Tooling\Process\ProcessResult(0, null, false, false, $output, '', 0);
            });
        });
    }

    public function testCopyStartsCanBeProvenBetweenSamplesWithoutInventingProcessConcurrency(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-copy-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory . '/maintenance.stdout', "BAANDER_MAINTENANCE_START:10.0\nBAANDER_MAINTENANCE_FINISH:20.0\n");
        $launch = ['executable' => 'ffmpeg', 'arguments' => ['-c:v', 'copy', '-c:a', 'copy']];
        file_put_contents($directory . '/native.jsonl', json_encode($launch + ['at' => 11], JSON_THROW_ON_ERROR) . "\n" . json_encode($launch + ['at' => 12], JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($directory . '/samples.jsonl', json_encode(['epochSeconds' => 13, 'processes' => []], JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($directory . '/database.jsonl', json_encode(['epochSeconds' => 15, 'snapshot' => ['jobs' => ['groups' => [['name' => 'SyncSongMessage', 'status' => 'running', 'count' => 1]]]]], JSON_THROW_ON_ERROR) . "\n");
        try {
            $proof = Qualification::copyOverlapProof($directory, 30, 2);
            self::assertTrue($proof['observed']);
            self::assertSame(2, $proof['copyLaunchesDuringMaintenance']);
            self::assertSame(0, $proof['sampledSimultaneousCopyProcessesLowerBound']);
            self::assertTrue(Qualification::copyOverlapProof($directory, 30, 1)['observed']);
        } finally {
            Collector::remove($directory);
        }
    }

    public function testCopyStartsAfterCommandDispatchWhileMetadataStillRuns(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-copy-async-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory . '/maintenance.stdout', "BAANDER_MAINTENANCE_START:10.0\nBAANDER_MAINTENANCE_FINISH:20.0\n");
        $launch = ['executable' => 'ffmpeg', 'arguments' => ['-c:v', 'copy', '-c:a', 'copy']];
        file_put_contents($directory . '/native.jsonl', json_encode($launch + ['at' => 21], JSON_THROW_ON_ERROR) . "\n" . json_encode($launch + ['at' => 22], JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($directory . '/samples.jsonl', json_encode(['epochSeconds' => 23, 'processes' => []], JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($directory . '/database.jsonl', json_encode(['epochSeconds' => 25, 'snapshot' => ['jobs' => ['groups' => [['name' => 'SyncSongMessage', 'status' => 'running', 'count' => 1]]]]], JSON_THROW_ON_ERROR) . "\n");
        try {
            $proof = Qualification::copyOverlapProof($directory, 30, 2);
            self::assertTrue($proof['observed']);
            self::assertSame(2, $proof['copyLaunchesDuringMaintenance']);
            self::assertSame(20.0, $proof['maintenanceCommandFinishedEpoch']);
            self::assertSame(30.0, $proof['maintenanceFinishedEpoch']);
            self::assertSame(25.0, $proof['lastPendingMetadataEpoch']);
            self::assertFalse(Qualification::copyOverlapProof($directory, 21, 2)['observed']);
            file_put_contents($directory . '/native.jsonl', json_encode($launch + ['at' => 26], JSON_THROW_ON_ERROR) . "\n" . json_encode($launch + ['at' => 27], JSON_THROW_ON_ERROR) . "\n");
            self::assertFalse(Qualification::copyOverlapProof($directory, 30, 2)['observed']);
            file_put_contents($directory . '/database.jsonl', json_encode(['epochSeconds' => 25, 'snapshot' => ['jobs' => ['groups' => [['name' => 'SyncSongMessage', 'status' => 'completed', 'count' => 1]]]]], JSON_THROW_ON_ERROR) . "\n");
            self::assertFalse(Qualification::copyOverlapProof($directory, 30, 2)['observed']);
        } finally {
            Collector::remove($directory);
        }
    }

    public function testMaintenanceLogParsesTheRetainedPrettyPrintedCommandDocuments(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander.app-maintenance-');
        try {
            $document = json_encode(['jobId' => 'fixture'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            file_put_contents($path, str_repeat($document . "\nBAANDER_MAINTENANCE_RESULT_END\n", 4));
            self::assertCount(4, Qualification::parseMaintenanceLog($path));
            file_put_contents($path, $document);
            $this->expectException(\RuntimeException::class);
            Qualification::parseMaintenanceLog($path);
        } finally {
            unlink($path);
        }
    }

    public function testFailedOrRetryingWorkCannotBecomeSuccessfulMaintenance(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['jobs']['groups'] = [['status' => 'failed', 'count' => 1, 'retried' => 0, 'max_attempt' => 1]];
        $queues = ['async' => $this->emptyQueue(), 'scheduler' => $this->emptyQueue()];
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['metadata']['completed']);
        $snapshot['jobs']['groups'] = [['status' => 'finished', 'count' => 1, 'retried' => 8, 'max_attempt' => 8]];
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['metadata']['completed']);
    }

    public function testObserverBootstrapIsExecutablePhpWithTheResolvedImageLayout(): void
    {
        $program = Observer::bootstrap(ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']));
        self::assertStringContainsString("'/srv/baander/scripts/vendor/autoload.php'", $program);
        self::assertStringContainsString('\\Baander\\Tooling\\Fixtures\\Qualification\\Observer::serve', $program);
        self::assertNotEmpty(token_get_all('<?php ' . $program, TOKEN_PARSE));
    }

    public function testObserverOnlyMapsOwnedRunningContainersAndRejectsRoleAmbiguity(): void
    {
        $container = ['Config' => ['Labels' => ['org.baander.preset.owner' => 'owner', 'com.docker.compose.service' => 'web']], 'State' => ['Running' => true, 'Pid' => 17]];
        self::assertSame(['web' => 17], Observer::rolesFromInspection([$container], 'deployment', 'owner'));
        self::assertSame([], Observer::rolesFromInspection([$container], 'deployment', 'foreign'));
        $this->expectException(\RuntimeException::class);
        Observer::rolesFromInspection([$container, $container], 'deployment', 'owner');
    }

    public function testNormalExitRequiresActualDieEventsForEveryOwnedProcess(): void
    {
        $containers = [['Id' => 'one', 'State' => ['Running' => true]], ['Id' => 'two', 'State' => ['Running' => true]]];
        $first = json_encode(['Action' => 'die', 'Actor' => ['ID' => 'one', 'Attributes' => ['exitCode' => '0']]], JSON_THROW_ON_ERROR) . "\n";
        $second = json_encode(['Action' => 'die', 'Actor' => ['ID' => 'two', 'Attributes' => ['exitCode' => '143']]], JSON_THROW_ON_ERROR) . "\n";
        self::assertFalse(Qualification::normalExits($containers, $first));
        self::assertTrue(Qualification::normalExits($containers, $first . $second));
        self::assertFalse(Qualification::normalExits($containers, $first . str_replace('143', '137', $second)));
    }

    public function testFailedStartRecordsEachServiceExitAndHealthState(): void
    {
        $web = ['Name' => '/baander-qualification-web-1', 'Config' => ['Labels' => ['com.docker.compose.service' => 'web']],
            'State' => ['Running' => false, 'ExitCode' => 255, 'OOMKilled' => true, 'Health' => ['Status' => 'unhealthy', 'Log' => [
                ['ExitCode' => 1, 'Output' => 'first'], ['ExitCode' => 1, 'Output' => 'second'], ['ExitCode' => 1, 'Output' => 'third'], ['ExitCode' => 1, 'Output' => str_repeat('x', 900)],
            ]]]];
        $redis = ['Name' => '/baander-qualification-redis-1', 'Config' => ['Labels' => ['com.docker.compose.service' => 'redis']],
            'State' => ['Running' => true, 'ExitCode' => 0, 'OOMKilled' => false]];
        $states = Qualification::serviceStates([$web, $redis]);
        self::assertSame(['name' => 'baander-qualification-web-1', 'service' => 'web', 'running' => false, 'exitCode' => 255, 'oomKilled' => true,
            'health' => 'unhealthy'], array_diff_key($states[0], ['healthLog' => true]));
        self::assertSame(['second', 'third'], array_slice(array_column($states[0]['healthLog'], 'output'), 0, 2));
        self::assertSame(512, strlen($states[0]['healthLog'][2]['output']));
        self::assertNull($states[1]['health']);
        self::assertSame([], $states[1]['healthLog']);
    }

    public function testOnlyUnansweredWebControlRequestsAreRetried(): void
    {
        self::assertTrue(Qualification::unansweredControlRequest("\n [ERROR] the web server closed the control connection without answering\n"));
        self::assertTrue(Qualification::unansweredControlRequest(' [ERROR] the web server did not answer within 5 seconds'));
        self::assertFalse(Qualification::unansweredControlRequest(' [ERROR] cannot connect to the web server control socket /run/control.sock: Permission denied'));
        self::assertFalse(Qualification::unansweredControlRequest(''));
    }

    public function testOnlyARetiredOrNeverRecordedWorkerPairCountsAsRetired(): void
    {
        self::assertTrue(Qualification::pairRetired(null));
        self::assertTrue(Qualification::pairRetired(['phase' => 'retired']));
        self::assertFalse(Qualification::pairRetired(['phase' => 'preparing']));
        self::assertFalse(Qualification::pairRetired(['phase' => 'ready']));
    }

    private function withPreparedQualification(\Closure $test, ?\Closure $configureExecutor = null): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-start-' . bin2hex(random_bytes(8));
        $configuration = Qualification::validateConfiguration($this->configuration());
        $runtime = new Runtime(dirname(__DIR__, 3), 5, getenv(), ToolingConfiguration::resolve());
        $executor = $this->createStub(CommandExecutor::class);
        if ($configureExecutor === null) {
            $executor->method('run')->willReturnCallback(self::hostMemoryDocker(0));
        } else {
            $configureExecutor($executor);
        }
        PrivateDirectory::prepare($directory);
        $target = new Target(self::cleanupDocker($directory), 'unix:///run/baander.app-docker.sock', 'fixture-daemon', null, null);
        $deployment = new PresetStateStore($directory . '/deployment');
        $deployment->save(['version' => 1, 'phase' => 'prepared', 'configuration' => $configuration['deployment'],
            'profile' => ['version' => 2, 'deployment' => ['capability' => 'remux', 'hardware' => null, 'webWorkers' => 2, 'doctrinePoolSlots' => 4],
                'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1], 'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320]],
            'layout' => ['version' => 1, 'root' => '/srv/baander'], 'owner' => 'fixture-owner']);
        $state = ['version' => 1, 'phase' => 'prepared', 'target' => $target->toArray(), 'configuration' => $configuration,
            'effectiveDeployment' => $configuration['deployment'], 'runs' => [], 'freshCopyConsumed' => false, 'datasetDigest' => str_repeat('d', 64),
            'images' => ['operator' => 'sha256:' . str_repeat('e', 64), 'worker' => 'sha256:' . str_repeat('f', 64), 'app' => 'sha256:' . str_repeat('a', 64), 'manifestDigest' => str_repeat('b', 64), 'manifest' => ['sourceRevision' => str_repeat('c', 64)]]];
        AtomicFile::writeJson($directory . '/qualification.json', $state);
        try {
            $test(new Qualification($runtime, new Client($executor, $target, getenv()), $directory, $configuration), $state, $directory);
        } finally {
            $runtime->close();
            Collector::remove($directory);
        }
    }

    /**
     * Answers the host memory observation container and fails every later Docker command, so a test sees
     * exactly what a qualification action does once the host check has passed or refused.
     *
     * @param list<ProcessSpec> $calls
     * @return \Closure(ProcessSpec): ProcessResult
     */
    private static function hostMemoryDocker(int $swapBytes, array &$calls = []): \Closure
    {
        $memoryContainer = str_repeat('9', 64);
        return static function (ProcessSpec $command) use ($swapBytes, &$calls, $memoryContainer): ProcessResult {
            $calls[] = $command;
            $arguments = array_slice($command->argv, 3);
            $output = match (true) {
                $arguments === ['info', '--format', '{{json .}}'] => '{"ID":"fixture-daemon"}',
                ($arguments[0] ?? null) === 'create' && str_contains(implode(' ', $arguments), '->memory()') => $memoryContainer,
                $arguments === ['start', '--attach', $memoryContainer] => json_encode(['memTotalBytes' => 4 * 1024 ** 3, 'swapTotalBytes' => $swapBytes], JSON_THROW_ON_ERROR),
                $arguments === ['inspect', '--format', '{{.State.ExitCode}}', $memoryContainer] => '0',
                default => throw new \RuntimeException('Simulated observer startup failure.'),
            };
            return new ProcessResult(0, null, false, false, $output, '', 0);
        };
    }

    /** A Docker command for resource cleanup that finds no remaining test container. */
    private static function cleanupDocker(string $directory): string
    {
        $path = $directory . '/docker';
        file_put_contents($path, <<<'SH'
            #!/bin/sh
            shift 2
            case "$1 $2" in
              "info --format") printf '{"ID":"fixture-daemon"}\n' ;;
              "container inspect") printf 'Error: No such container: %s\n' "$4" >&2; exit 1 ;;
            esac
            SH);
        chmod($path, 0700);
        return $path;
    }

    /** @return array<string,mixed> */
    private function configuration(): array
    {
        return ['version' => 1, 'name' => 'baander-qualification', 'deployment' => [
            'version' => 2, 'name' => 'baander-qualification', 'capability' => 'remux', 'size' => self::SIZE, 'origin' => 'http://baander.app:8080',
            'listen' => ['address' => '127.0.0.1', 'port' => 8080],
            'images' => array_fill_keys(['application', 'worker', 'operator', 'postgres', 'gateway', 'redis'], 'baander/fixture:local'),
            'environment' => ['APP_SECRET' => 'private-app', 'STREAM_URL_HMAC_SECRET' => 'private-stream', 'DB_USERNAME' => 'baander', 'DB_PASSWORD' => 'private-db', 'DB_DATABASE' => 'baander', 'REDIS_PASSWORD' => 'private-redis'],
        ], 'datasetRun' => 'repeatable-media',
            'host' => ['constraintBytes' => 12 * 1024 ** 3, 'storage' => 'disposable VM disk', 'description' => 'four CPU twelve GiB VM']];
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        $libraries = [];
        foreach (['music' => 2000, 'movies' => 50, 'episodes' => 250] as $role => $count) {
            $libraries[$role] = ['exists' => true, 'scanStatus' => 'completed', 'missingCatalogFiles' => 0, 'indexedFiles' => $count, 'indexedAvailableFiles' => $count, 'indexedUnavailableFiles' => 0, 'songs' => $count, 'videos' => $count,
                'songsWithMbid' => $count, 'moviesWithTmdbId' => $count, 'albumsWithMbid' => 100];
        }
        return ['libraries' => $libraries, 'outbox' => ['dead' => 0, 'pending' => 0], 'jobs' => ['groups' => [['status' => 'finished', 'count' => 2300, 'retried' => 0, 'max_attempt' => 1]]]];
    }

    /** @return array<string,mixed> */
    private function emptyQueue(): array
    {
        return ['state' => 'known', 'unreadCount' => 0, 'pendingCount' => 0, 'dueCount' => 0];
    }
}
