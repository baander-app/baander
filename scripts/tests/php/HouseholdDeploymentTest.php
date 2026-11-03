<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Deployment\HouseholdConfiguration;
use Baander\Tooling\Deployment\HouseholdStateStore;
use Baander\Tooling\Deployment\HouseholdRenderer;
use Baander\Tooling\Deployment\ImageProfile;
use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Deployment\Household;
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\{CommandExecutor, CompletionPolicy, EngineExecutor, ExecutionContext, ProcessResult, ProcessSpec};
use PHPUnit\Framework\TestCase;

final class HouseholdDeploymentTest extends TestCase
{
    /** @return array<string,mixed> */
    public static function configuration(): array
    {
        return [
            'version' => 1, 'name' => 'baander-household', 'profile' => 'household',
            'origin' => 'http://baander.app:8080',
            'listen' => ['address' => '127.0.0.1', 'port' => 8080],
            'images' => array_fill_keys(['application', 'worker', 'operator', 'postgres', 'gateway', 'redis'], 'baander/fixture:local'),
            'environment' => ['APP_SECRET' => 'private-app-secret', 'STREAM_URL_HMAC_SECRET' => 'private-stream-secret', 'DB_USERNAME' => 'baander', 'DB_PASSWORD' => 'private-database-secret', 'DB_DATABASE' => 'baander', 'REDIS_PASSWORD' => 'private-redis-secret'],
        ];
    }

    public function testProfilesShareExplicitServiceLimitsAndRetainDistinctWorkerSelection(): void
    {
        $household = HouseholdConfiguration::fromArray(self::configuration());
        $full = HouseholdConfiguration::fromArray(array_replace(self::configuration(), ['profile' => 'full']));
        self::assertSame($household->resources, $full->resources);
        self::assertSame('household', $household->profile);
        self::assertSame('full', $full->profile);
        self::assertSame('baander-household-media', $household->volumes['media']);
        self::assertSame('private-database-secret', $household->environment['DB_PASSWORD']);
    }

    public function testConfigurationRejectsUnknownProfilesAndHostSourceMounts(): void
    {
        $data = self::configuration();
        foreach ([
            [['profile' => 'tiny'], 'Select the household or full deployment profile.'],
            [['media' => ['/home/operator/music']], 'Unknown key in household configuration.'],
            [['volumes' => ['media' => '/home/operator/music']], 'Persistent storage requires named Docker volumes.'],
            [['environment' => $data['environment'] + ['BAANDER_DEPLOYMENT_PROFILE' => 'full']], 'Deployment derives environment variable BAANDER_DEPLOYMENT_PROFILE.'],
        ] as [$override, $message]) {
            try {
                HouseholdConfiguration::fromArray(array_replace($data, $override));
                self::fail('Accepted an invalid household configuration.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function testOriginRejectsUserInfoAndControlCharacters(): void
    {
        foreach (['https://operator@baander.app', "https://baander.app\n.invalid", 'https://baander.app/other'] as $origin) {
            try {
                HouseholdConfiguration::fromArray(array_replace(self::configuration(), ['origin' => $origin]));
                self::fail('Accepted an invalid origin.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testRendererPinsImagesRetainsDataAndEscapesComposeInterpolation(): void
    {
        $data = self::configuration();
        $data['environment']['DB_PASSWORD'] = 'secret$literal';
        $configuration = HouseholdConfiguration::fromArray($data);
        $profile = $this->profile();
        $workerEnvironment = HouseholdRenderer::workerEnvironment($configuration, $profile);
        self::assertSame('household', $workerEnvironment['BAANDER_DEPLOYMENT_PROFILE']);
        self::assertSame($profile->deployment, json_decode($workerEnvironment['BAANDER_DEPLOYMENT_PROFILE_JSON'], true, flags: JSON_THROW_ON_ERROR));
        $images = $this->images();
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        $compose = HouseholdRenderer::render($configuration, $images, $layout, $profile, 'max_connections = 36', 'owner');
        self::assertSame(['postgres', '-c', 'config_file=/etc/baander/postgresql.conf'], $compose['services']['postgres']['command']);
        self::assertSame('secret$$literal', $compose['services']['postgres']['environment']['POSTGRES_PASSWORD']);
        self::assertStringContainsString('$http_upgrade', HouseholdRenderer::configurationFiles($configuration, 'max_connections = 36')['gateway_configuration']['content']);
        self::assertStringContainsString('noeviction', $compose['services']['redis']['command'][2]);
        self::assertSame('memory_limit=256M', $compose['services']['web']['command'][1]);
        self::assertSame($profile->fingerprint(), $compose['services']['web']['labels']['org.baander.deployment.fingerprint']);
        $roles = ['web' => 'application', 'postgres' => 'postgres', 'redis' => 'redis', 'gateway' => 'gateway'];
        foreach ($compose['services'] as $name => $service) {
            self::assertSame($images[$roles[$name]]['id'], $service['image']);
            self::assertArrayNotHasKey('privileged', $service);
            self::assertSame($service['mem_limit'], $service['memswap_limit']);
            foreach ($service['volumes'] ?? [] as $mount) {
                self::assertSame('volume', $mount['type']);
                self::assertNotSame('/var/run/docker.sock', $mount['target']);
                self::assertNotSame('/srv/baander/var', $mount['target']);
            }
        }
        foreach ($compose['volumes'] as $volume) {
            self::assertTrue($volume['external']);
        }
        self::assertTrue($compose['services']['web']['volumes'][0]['read_only']);
        self::assertContains(['type' => 'volume', 'source' => 'secrets', 'target' => '/srv/baander/config/secrets', 'read_only' => true], $compose['services']['web']['volumes']);
        self::assertNotContains($configuration->volumes['secrets'], array_column(HouseholdRenderer::mediaMounts($configuration, $layout), 'volume'));
        $reservations = HouseholdRenderer::reservations($configuration, $profile);
        self::assertSame(2304, $reservations['controlMemoryMiB']);
        self::assertSame(4480, $reservations['executionMemoryMiB']);
        self::assertSame(10304, $reservations['totalMiB']);
        self::assertArrayNotHasKey('DB_PASSWORD', $workerEnvironment);
        self::assertArrayNotHasKey('APP_DOMAIN', $workerEnvironment);
        self::assertSame('household', $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE']);
    }

    public function testRendererRejectsMixedProfiles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Configured and resolved deployment profiles disagree.');
        HouseholdRenderer::render(HouseholdConfiguration::fromArray(array_replace(self::configuration(), ['profile' => 'full'])), $this->images(), ApplicationLayout::load(), $this->profile(), 'max_connections = 36', 'owner');
    }

    public function testMigrationsTransferGeneratedCredentialsThroughTheRealDisposableWrapper(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-household-' . bin2hex(random_bytes(8));
        $store = new HouseholdStateStore($directory);
        copy(__DIR__ . '/Fixtures/development-lifecycle-docker.php', $directory . '/docker');
        chmod($directory . '/docker', 0700);
        AtomicFile::writeJson($directory . '/daemon.json', ['daemonId' => 'owned-daemon']);
        $actual = new EngineExecutor(ExecutionContext::bounded(10, getenv()));
        $executor = $this->createStub(CommandExecutor::class);
        $created = null;
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $command, CompletionPolicy $policy) use ($actual, &$created): ProcessResult {
            if (in_array('create', $command->argv, true)) {
                $created = $command;
            }
            return $actual->run($command, $policy);
        });
        $target = new Target($directory . '/docker', 'unix:///run/baander.app-docker.sock', 'owned-daemon', null, null);
        $configuration = HouseholdConfiguration::fromArray(self::configuration());
        $deployment = new Household($store, new Client($executor, $target, getenv()), $executor, getenv(), dirname(__DIR__, 3));
        try {
            new \ReflectionMethod(Household::class, 'migrate')->invoke(
                $deployment,
                $configuration,
                ['owner' => 'fixture-owner', 'images' => ['application' => ['id' => 'sha256:' . str_repeat('a', 64)]]],
                ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']),
                $this->profile(),
            );
            self::assertInstanceOf(ProcessSpec::class, $created);
            self::assertSame('postgresql://baander:private-database-secret@postgres:5432/baander?serverVersion=18&charset=utf8', $created->environment['DATABASE_URL']);
            self::assertSame('redis://default:private-redis-secret@redis:6379/messages', $created->environment['MESSENGER_TRANSPORT_DSN']);
            self::assertSame('prod', $created->environment['APP_ENV']);
            self::assertContains('doctrine:migrations:migrate', $created->argv);
            self::assertContains('--cgroupns', $created->argv);
            self::assertStringContainsString('BAANDER_OPERATOR_MEASURE_V1', implode(' ', $created->argv));
            $measurements = glob($directory . '/helper-measurements/fixture-owner/migration-*.json');
            self::assertCount(1, $measurements);
            self::assertSame('incomplete', AtomicFile::readJson($measurements[0])['status']);
            mkdir($directory . '/runtime/bin', 0700, true);
            $console = $directory . '/runtime/bin/console';
            file_put_contents($console, '<?php $_SERVER["APP_RUNTIME"] = "Symfony\\Component\\Runtime\\GenericRuntime"; require_once '
                . var_export(dirname(__DIR__, 3) . '/vendor/autoload_runtime.php', true)
                . '; return static function (array $context, array $argv): Closure { return static function () use ($context, $argv): int { echo json_encode(["argv" => $argv, "argc" => $context["argc"], "script" => $context["SCRIPT_FILENAME"]], JSON_THROW_ON_ERROR); return 23; }; };');
            $program = $created->argv[array_search('-r', $created->argv, true) + 1];
            self::assertNotContains('private-database-secret', $created->argv);
            self::assertFileDoesNotExist($directory . '/migration.json');
            new \ReflectionMethod(Household::class, 'initializeSecrets')->invoke(
                $deployment,
                $configuration,
                ['owner' => 'fixture-owner', 'images' => ['application' => ['id' => 'sha256:' . str_repeat('a', 64)]]],
                ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']),
                $this->profile(),
            );
            $secretsProgram = $created->argv[array_search('-r', $created->argv, true) + 1];
            $migration = $actual->run(new ProcessSpec([PHP_BINARY, '-r', $program, $console, 'doctrine:migrations:migrate', '--no-interaction']), new CompletionPolicy([0, 23]));
            $secrets = $actual->run(new ProcessSpec([PHP_BINARY, '-r', $secretsProgram, $directory . '/runtime']), new CompletionPolicy([0, 23]));
            self::assertSame([23, 23], [$migration->exitCode, $secrets->exitCode]);
            self::assertSame(['argv' => [$console, 'doctrine:migrations:migrate', '--no-interaction'], 'argc' => 3, 'script' => $console], json_decode($migration->stdout, true, flags: JSON_THROW_ON_ERROR));
            self::assertSame(['argv' => [$console, 'app:oauth:generate-keys', '--no-interaction'], 'argc' => 3, 'script' => $console], json_decode($secrets->stdout, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            $actual->close();
            Collector::remove($directory);
        }
    }

    public function testStartingWithAnotherVolumeInventoryDoesNotStopTheSavedDeployment(): void
    {
        $this->withSavedDeployment(function (Household $deployment, HouseholdStateStore $store, array &$commands): void {
            $configuration = self::configuration();
            $configuration['volumes'] = ['media' => 'another-media-volume'];
            try {
                $deployment->start(HouseholdConfiguration::fromArray($configuration));
                self::fail('Accepted a different persistent volume inventory.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('separate state directory', $error->getMessage());
            }
            self::assertSame([], $commands);
            self::assertSame('starting', $store->load()['phase']);
        });
    }

    public function testStopKeepsNamedVolumesAndCanRecoverAnInterruptedStart(): void
    {
        $this->withSavedDeployment(function (Household $deployment, HouseholdStateStore $store, array &$commands): void {
            $result = $deployment->stop();
            self::assertSame('stopped', $result['phase']);
            self::assertSame('stopped', $store->load()['phase']);
            $down = array_values(array_filter($commands, static fn(array $arguments): bool => in_array('down', $arguments, true)));
            self::assertCount(1, $down);
            self::assertNotContains('--volumes', $down[0]);
            self::assertNotContains('-v', $down[0]);
            self::assertSame('baander-household-media', $result['dataVolumes']['media']);
        });
    }

    public function testStopRefusesForeignComposeContainersBeforeRemovingAnything(): void
    {
        $this->withSavedDeployment(function (Household $deployment, HouseholdStateStore $store, array &$commands): void {
            try {
                $deployment->stop();
                self::fail('Removed an unowned Compose project.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('another deployment', $error->getMessage());
            }
            self::assertSame('starting', $store->load()['phase']);
            self::assertSame([], array_values(array_filter($commands, static fn(array $arguments): bool => in_array('down', $arguments, true))));
        }, true);
    }

    private function withSavedDeployment(\Closure $test, bool $foreign = false): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-household-' . bin2hex(random_bytes(8));
        $store = new HouseholdStateStore($directory);
        $target = new Target('/usr/bin/docker', 'unix:///run/baander.app-docker.sock', 'owned-daemon', null, null);
        $commands = [];
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $command) use (&$commands, $foreign): ProcessResult {
            $arguments = array_slice($command->argv, 3);
            $commands[] = $arguments;
            $output = match ($arguments[0]) {
                'info' => '{"ID":"owned-daemon"}',
                'inspect' => json_encode([['Config' => ['Labels' => ['org.baander.household.owner' => 'foreign']]]], JSON_THROW_ON_ERROR),
                'compose' => in_array('ps', $arguments, true) && $foreign ? 'container-id' : '',
                default => '',
            };
            return new ProcessResult($arguments[0] === 'network' ? 1 : 0, null, false, false, $output, '', 0);
        });
        AtomicFile::write($directory . '/compose-fixture.json', '{}');
        $store->save(['version' => 1, 'phase' => 'starting', 'owner' => 'owned',
            'configuration' => HouseholdConfiguration::fromArray(self::configuration())->toArray(),
            'target' => $target->toArray(), 'profile' => $this->profile()->toArray(), 'profileFingerprint' => $this->profile()->fingerprint(),
            'composeFile' => $directory . '/compose-fixture.json', 'composeHash' => hash('sha256', '{}'), 'configurationVolumes' => []]);
        try {
            $test(new Household($store, new Client($executor, $target, []), $executor, [], dirname(__DIR__, 3)), $store, $commands);
        } finally {
            Collector::remove($directory);
        }
    }

    /** @return array<string,array{id:string}> */
    private function images(): array
    {
        $images = [];
        foreach (HouseholdConfiguration::IMAGE_ROLES as $index => $role) {
            $images[$role] = ['id' => 'sha256:' . str_repeat(dechex($index + 1), 64)];
        }
        return $images;
    }

    private function profile(): ImageProfile
    {
        return ImageProfile::fromArray([
            'version' => 1,
            'deployment' => ['identifier' => 'household', 'webWorkers' => 2, 'doctrinePoolSlots' => 4],
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1],
            'runtime' => ['phpHeapMiB' => 256],
            'budgets' => ['controlMemoryMiB' => 2304, 'executionMemoryMiB' => 4480, 'controlPidsLimit' => 129, 'executionPidsLimit' => 256, 'scheduledConsoleMiB' => 320],
        ]);
    }

    public function testStateUsesPrivateAtomicDocumentsAndExclusiveLock(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-household-' . bin2hex(random_bytes(8));
        $store = new HouseholdStateStore($directory);
        try {
            self::assertNull($store->load());
            $store->save(['version' => 1, 'phase' => 'prepared']);
            self::assertSame('prepared', $store->load()['phase']);
            self::assertSame(0600, fileperms($directory . '/state.json') & 0777);
            $lock = $store->lock();
            try {
                $this->expectException(\RuntimeException::class);
                $store->lock();
            } finally {
                $lock->release();
            }
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
