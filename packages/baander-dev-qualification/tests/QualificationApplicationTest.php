<?php

declare(strict_types=1);

namespace Baander\DevQualification\Tests;

use Baander\DevQualification\Tests\Support\QualificationApplicationRunner;
use Baander\Containers\Docker\Client;
use Baander\Containers\Docker\Target;
use Baander\DevQualification\Fixtures\Qualification\Application;
use Baander\DevQualification\Fixtures\Qualification\Dataset;
use Baander\Process\Execution\CommandExecutor;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Process\Execution\ProcessResult;
use Baander\Process\Execution\ProcessSpec;
use Baander\Process\Execution\RunningProcess;
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
            'environment' => ['APP_SECRET' => 'private-app-secret', 'STREAM_URL_HMAC_SECRET' => 'private-stream-secret', 'AUTH_TOTP_ENCRYPTION_KEY' => 'cHJpdmF0ZS10b3RwLWVuY3J5cHRpb24ta2V5LTMyYiE=', 'DB_USERNAME' => 'baander', 'DB_PASSWORD' => 'private-database-secret', 'DB_DATABASE' => 'baander', 'REDIS_PASSWORD' => 'private-redis-secret'],
        ];
        $configuration['volumes'] = ['media' => 'fixture-media'];
        $state = ['version' => 1, 'phase' => 'running', 'owner' => 'fixture-owner', 'configuration' => $configuration, 'layout' => ['version' => 1, 'root' => '/srv/baander'], 'composeFile' => '/tmp/fixture-compose.json', 'target' => $docker->target->toArray(), 'images' => ['application' => ['id' => 'sha256:' . str_repeat('a', 64)], 'postgres' => ['id' => 'sha256:' . str_repeat('b', 64)]]];
        return [new Application($docker, $state, $libraries), $runner];
    }
}
