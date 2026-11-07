<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Fixtures\Qualification\{Application, Dataset};
use Baander\Tooling\Process\{CommandExecutor, CompletionPolicy, ProcessResult, ProcessSpec, RunningProcess};
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QualificationApplicationTest extends TestCase
{
    public function testProvisionUsesNormalCommandsAndKeepsPasswordsOffArgumentsAndReceipts(): void
    {
        [$app, $runner] = $this->application();
        $users = $this->users();
        $result = $app->provision(Dataset::plan('fixture-run'), $users);
        self::assertCount(6, $result['users']);
        self::assertSame('movie', $result['libraries']['episodes']['type']);
        self::assertSame('/storage/media/fixture-run/episodes', $result['libraries']['episodes']['path']);
        self::assertSame('first_party', $result['oauth']['type']);
        foreach ($users as $user) {
            self::assertStringNotContainsString($user['password'], json_encode($result, JSON_THROW_ON_ERROR));
        }
        $creates = array_values(array_filter($runner->calls, static fn(ProcessSpec $call): bool => in_array('app:user:create', $call->argv, true)));
        self::assertCount(6, $creates);
        foreach ($creates as $index => $call) {
            self::assertSame($users[$index]['password'] . "\n", $call->stdin);
            self::assertNotContains($users[$index]['password'], $call->argv);
            self::assertContains('--password', $call->argv);
            self::assertNull($call->logPath);
        }
        self::assertCount(18, array_filter($runner->calls, static fn(ProcessSpec $call): bool => in_array('app:library:member:grant', $call->argv, true)));
    }

    public function testRejectsReusedAccountsAndEscapingDatasetPathsBeforeMutation(): void
    {
        foreach (['users', 'path'] as $case) {
            [$app, $runner] = $this->application();
            $users = $this->users();
            $dataset = Dataset::plan('fixture-run');
            if ($case === 'users') {
                $users[5] = $users[0];
            } else {
                $dataset['libraries']['episodes']['path'] = 'fixture-run/../outside';
            }
            try {
                $app->provision($dataset, $users);
                self::fail('Invalid fixture provisioning accepted');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
            self::assertSame([], array_filter($runner->calls, static fn(ProcessSpec $call): bool => in_array('app:user:create', $call->argv, true)));
        }
    }

    public function testRejectsWrongSourceVolumeBeforeProvisioning(): void
    {
        [$app, $runner] = $this->application();
        $runner->wrongVolume = true;
        $this->expectExceptionMessage('media volume');
        $app->provision(Dataset::plan('fixture-run'), $this->users());
    }

    public function testScansUseResolvedPublicIdsAndMetadataReceiptReportsItsGlobalScope(): void
    {
        [$app, $runner] = $this->application();
        $provisioned = $app->provision(Dataset::plan('fixture-run'), $this->users());
        [$restored] = $this->application($runner, $provisioned['libraries']);
        $restored->startScan(array_column($provisioned['libraries'], 'id'));
        $scans = array_values(array_filter($runner->calls, static fn(ProcessSpec $call): bool => in_array('app:library:scan', $call->argv, true)));
        self::assertCount(3, $scans);
        self::assertContains('LLLLLLLLLLLLLLLLLLLL0', $scans[0]->argv);
        self::assertSame('all-libraries', $app->startMetadataSync()['scope']);
    }

    public function testPlaybackLookupRequiresActualCatalogRowsForEveryRequestedPath(): void
    {
        [$app, $runner] = $this->application();
        $dataset = Dataset::plan('fixture-run');
        $slots = $app->playbackSlots($dataset);
        self::assertSame('/movies/MMMMMMMMMMMMMMMMMMMMM', $slots['originalVideos'][0]['route']);
        self::assertSame('/albums/AAAAAAAAAAAAAAAAAAAAA', $slots['music']['route']);
        self::assertSame('SSSSSSSSSSSSSSSSSSSSS', $slots['music']['songPublicId']);
        $runner->missingCatalog = true;
        $this->expectExceptionMessage('catalog');
        $app->playbackSlots($dataset);
    }

    public function testCommandFailuresAreReportedWithoutLeakingRawOutput(): void
    {
        [$app, $runner] = $this->application();
        $runner->fail = true;
        try {
            $app->provision(Dataset::plan('fixture-run'), $this->users());
            self::fail('Failed application command accepted');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('private-secret', $error->getMessage());
        }
    }

    public function testStagedScanRejectsChangedCatalogPathBeforeScanning(): void
    {
        [$app, $runner] = $this->application();
        $provisioned = $app->provision(Dataset::plan('fixture-run'), $this->users());
        [$restored] = $this->application($runner, $provisioned['libraries']);
        $runner->changedLibrary = true;
        $this->expectExceptionMessage('identity changed');
        $restored->startScan(array_column($provisioned['libraries'], 'id'));
    }

    public function testRejectsNonDefaultLoginClientBeforeCreatingUsers(): void
    {
        [$app, $runner] = $this->application();
        $runner->customClient = true;
        try {
            $app->provision(Dataset::plan('fixture-run'), $this->users());
            self::fail('Unsupported login client accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('AUTH_SPA_CLIENT_ID', $error->getMessage());
        }
        self::assertSame([], array_filter($runner->calls, static fn(ProcessSpec $call): bool => in_array('app:user:create', $call->argv, true)));
    }

    public function testQueueSamplesPreserveUnknownStateAndRejectMissingEvidence(): void
    {
        [$app, $runner] = $this->application();
        $queues = $app->queues();
        self::assertSame('known', $queues['async']['state']);
        self::assertSame(0, $queues['async']['pendingCount']);
        self::assertSame('uninitialized', $queues['scheduler']['state']);
        $runner->missingQueues = true;
        $this->expectExceptionMessage('queue observation');
        $app->queues();
    }

    /** @return list<array{email:string,name:string,password:string}> */
    private function users(): array
    {
        return array_map(static fn(int $index): array => ['email' => 'qualification-' . $index . '@baander.app', 'name' => 'Fixture ' . $index, 'password' => 'private-secret-' . $index], range(1, 6));
    }

    /** @param array<string,array<string,mixed>> $libraries
     * @return array{Application, QualificationApplicationRunner}
     */
    private function application(?QualificationApplicationRunner $runner = null, array $libraries = []): array
    {
        $runner ??= new QualificationApplicationRunner();
        $docker = new Client($runner, new Target('/usr/bin/docker', 'unix:///fixture.sock', 'fixture', null, null), []);
        $configuration = [
            'version' => 2, 'name' => 'qualification-fixture', 'capability' => 'remux',
            'size' => ['webWorkers' => 2, 'databaseSlots' => 4, 'ordinaryConsumers' => 1, 'sharedBuffersMiB' => 128, 'workMemMiB' => 4, 'copySlots' => 2],
            'origin' => 'https://baander.app', 'listen' => ['address' => '127.0.0.1', 'port' => 8080],
            'images' => array_fill_keys(['application', 'worker', 'operator', 'postgres', 'gateway', 'redis'], 'baander/fixture:local'),
            'environment' => ['APP_SECRET' => 'private-app-secret', 'STREAM_URL_HMAC_SECRET' => 'private-stream-secret', 'DB_USERNAME' => 'baander', 'DB_PASSWORD' => 'private-database-secret', 'DB_DATABASE' => 'baander', 'REDIS_PASSWORD' => 'private-redis-secret'],
        ];
        $configuration['volumes'] = ['media' => 'fixture-media'];
        $state = ['version' => 1, 'phase' => 'running', 'owner' => 'fixture-owner', 'configuration' => $configuration, 'layout' => ['version' => 1, 'root' => '/srv/baander'], 'composeFile' => '/tmp/fixture-compose.json', 'target' => $docker->target->toArray(), 'images' => ['application' => ['id' => 'sha256:' . str_repeat('a', 64)], 'postgres' => ['id' => 'sha256:' . str_repeat('b', 64)]]];
        return [new Application($docker, $state, $libraries), $runner];
    }
}

final class QualificationApplicationRunner implements CommandExecutor
{
    /** @var list<ProcessSpec> */
    public array $calls = [];
    public bool $wrongVolume = false;
    public bool $missingCatalog = false;
    public bool $fail = false;
    public bool $changedLibrary = false;
    public bool $customClient = false;
    public bool $missingQueues = false;
    private int $user = 0;
    /** @var list<array<string,string>> */
    private array $libraries = [];

    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        $this->calls[] = $command;
        $args = $command->argv;
        $output = '{}';
        if (in_array('ps', $args, true)) {
            $output = str_repeat(in_array('web', $args, true) ? 'a' : 'b', 64);
        } elseif (in_array('inspect', $args, true)) {
            $web = in_array(str_repeat('a', 64), $args, true);
            $output = json_encode([['Id' => str_repeat($web ? 'a' : 'b', 64), 'Image' => 'sha256:' . str_repeat($web ? 'a' : 'b', 64), 'State' => ['Running' => true], 'Config' => ['Labels' => ['org.baander.preset.owner' => 'fixture-owner']], 'Mounts' => [['Type' => 'volume', 'Name' => $this->wrongVolume ? 'wrong-volume' : 'fixture-media', 'Destination' => '/storage/media', 'RW' => false]]]], JSON_THROW_ON_ERROR);
        } elseif (in_array('app:user:create', $args, true)) {
            ++$this->user;
            $output = json_encode(['publicId' => str_repeat('U', 20) . $this->user, 'email' => $args[array_search('app:user:create', $args, true) + 1]], JSON_THROW_ON_ERROR);
        } elseif (in_array('app:oauth:client:create', $args, true)) {
            $output = json_encode(['clientId' => str_repeat('C', 21), 'type' => 'public', 'clientSecret' => null], JSON_THROW_ON_ERROR);
        } elseif (in_array('app:library:create', $args, true)) {
            $start = array_search('app:library:create', $args, true);
            $index = count($this->libraries);
            $library = ['id' => sprintf('00000000-0000-4000-8000-%012d', $index), 'publicId' => str_repeat('L', 20) . $index, 'path' => $args[$start + 2], 'type' => $args[$start + 3]];
            $this->libraries[] = $library;
            $output = json_encode($library, JSON_THROW_ON_ERROR);
        } elseif (in_array('app:library:member:grant', $args, true)) {
            $output = '{"granted":true}';
        } elseif (in_array('app:metadata:sync', $args, true)) {
            $output = '{"jobsDispatched":3,"jobId":"metadata-job"}';
        } elseif (in_array('app:library:scan', $args, true)) {
            $output = '{"scanStatus":"idle"}';
        } elseif (in_array('psql', $args, true)) {
            if (str_contains($command->stdin ?? '', 'FROM oauth_clients')) {
                $output = '[{"clientId":"baander_dev_spa_00001","firstParty":true,"passwordClient":true,"revoked":false}]';
            } elseif (str_contains($command->stdin ?? '', 'playback_catalog')) {
                $rows = [];
                if (!$this->missingCatalog) {
                    foreach (array_merge(array_slice(Dataset::plan('fixture-run')['files'], 0, 2), array_slice(Dataset::plan('fixture-run')['files'], 2000, 8)) as $file) {
                        $rows[] = ['path' => '/storage/media/' . $file['path'], 'title' => $file['title'], 'songPublicId' => $file['kind'] === 'song' ? str_repeat('S', 21) : null, 'albumPublicId' => $file['kind'] === 'song' ? str_repeat('A', 21) : null, 'moviePublicId' => $file['kind'] !== 'song' ? str_repeat('M', 21) : null, 'videoPublicId' => $file['kind'] !== 'song' ? str_repeat('V', 21) : null];
                    }
                }
                $output = json_encode($rows, JSON_THROW_ON_ERROR);
            } else {
                $libraries = $this->libraries;
                if ($this->changedLibrary) {
                    $libraries[0]['path'] = '/storage/media/another-run/music';
                }
                $output = json_encode($libraries, JSON_THROW_ON_ERROR);
            }
        }
        if (str_contains($command->stdin ?? '', 'AUTH_SPA_CLIENT_ID')) {
            $output = json_encode(['clientId' => $this->customClient ? 'different-client' : 'baander_dev_spa_00001'], JSON_THROW_ON_ERROR);
        }
        if (str_contains($command->stdin ?? '', 'RedisQueueSampler')) {
            $output = $this->missingQueues ? '{}' : '{"async":{"state":"known","sampledAt":1.5,"unreadCount":0,"pendingCount":0,"dueCount":0,"futureCount":0},"scheduler":{"state":"uninitialized","sampledAt":1.5,"unreadCount":null,"pendingCount":null,"dueCount":0,"futureCount":0}}';
        }
        return new ProcessResult($this->fail ? 1 : 0, null, false, false, $output, $this->fail ? 'private-secret' : '', .01);
    }

    public function start(ProcessSpec $command): RunningProcess
    {
        throw new \LogicException('Unexpected start');
    }
    public function wait(RunningProcess $process, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        throw new \LogicException('Unexpected wait');
    }
    public function stop(RunningProcess $process, int $signal = 15): ProcessResult
    {
        throw new \LogicException('Unexpected stop');
    }
}
