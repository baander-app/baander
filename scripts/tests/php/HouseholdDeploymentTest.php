<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Deployment\HouseholdConfiguration;
use Baander\Tooling\Deployment\HouseholdStateStore;
use Baander\Tooling\Deployment\HouseholdRenderer;
use Baander\Tooling\Deployment\ImageProfile;
use Baander\Tooling\Configuration\{ApplicationLayout, JsonObject};
use Baander\Tooling\Deployment\Household;
use Baander\Tooling\Deployment\KillOrder;
use Baander\Tooling\Deployment\Pooler;
use Baander\Tooling\Deployment\PostgresSettings;
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\{CommandExecutor, CompletionPolicy, EngineExecutor, ExecutionContext, ProcessResult, ProcessSpec};
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\MediaSlotBounds;
use App\Shared\Infrastructure\Worker\OutOfMemoryKillOrder;
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

    public function testRenderedServicesCarryNoContainerLimitsAndRenderTheKillOrder(): void
    {
        $household = HouseholdConfiguration::fromArray(self::configuration());
        $full = HouseholdConfiguration::fromArray(array_replace(self::configuration(), ['profile' => 'full']));
        self::assertSame(['redisMaxmemoryMiB' => 192], $household->resources);
        self::assertSame($household->resources, $full->resources);
        foreach ([['resources' => ['webMemoryMiB' => 1536]], ['resources' => ['operatorMemoryMiB' => 512]], ['resources' => ['postgresMemoryMiB' => 1536]], ['controlCpuSet' => [0]], ['executionCpuSet' => [1]]] as $override) {
            try {
                HouseholdConfiguration::fromArray(array_replace(self::configuration(), $override));
                self::fail('Accepted a container limit or CPU set.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Unknown key', $error->getMessage());
            }
        }
        self::assertSame('household', $household->profile);
        self::assertSame('full', $full->profile);
        self::assertSame('baander-household-media', $household->volumes['media']);
        self::assertSame('private-database-secret', $household->environment['DB_PASSWORD']);
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        $scores = ['web' => KillOrder::WEB, 'postgres' => KillOrder::POSTGRES, 'pooler' => KillOrder::POOLER, 'redis' => KillOrder::REDIS, 'gateway' => KillOrder::GATEWAY];
        foreach ([[$household, $this->profile()], [$full, $this->profile('full')]] as [$configuration, $profile]) {
            $compose = HouseholdRenderer::render($configuration, $this->images(), $layout, $profile, 'max_connections = 20', 'owner');
            self::assertSame(array_keys($scores), array_keys($compose['services']));
            foreach ($compose['services'] as $name => $service) {
                foreach (['mem_limit', 'memswap_limit', 'mem_reservation', 'pids_limit', 'cpus', 'cpu_shares', 'cpuset', 'cpu_quota', 'cpu_period', 'deploy'] as $limit) {
                    self::assertArrayNotHasKey($limit, $service, $configuration->profile . ' ' . $name . ' ' . $limit);
                }
                self::assertSame($scores[$name], $service['oom_score_adj'], $configuration->profile . ' ' . $name);
            }
            self::assertStringContainsString('--maxmemory 192mb', $compose['services']['redis']['command'][2]);
        }
    }

    public function testKillOrderSacrificesWebAndMediaWorkBeforeStatefulServices(): void
    {
        $order = [
            'web' => KillOrder::WEB,
            'media work' => OutOfMemoryKillOrder::MEDIA_WORK,
            'execution unit' => OutOfMemoryKillOrder::EXECUTION_UNIT,
            'redis' => KillOrder::REDIS,
            'gateway' => KillOrder::GATEWAY,
            'postgres' => KillOrder::POSTGRES,
        ];
        self::assertSame([900, 600, 300, 100, 0, -500], array_values($order));
        $scores = array_values($order);
        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores);
        self::assertSame(count($scores), count(array_unique($scores)));
        self::assertSame(KillOrder::POSTGRES, KillOrder::POOLER);
        self::assertSame(KillOrder::POSTGRES, OutOfMemoryKillOrder::CONTROL_UNIT);
        self::assertLessThan(0, KillOrder::POSTGRES);
    }

    public function testHouseholdRendersItsSharedCopyProfileAndNativeLimitsUnchanged(): void
    {
        $application = new DeploymentProfile('household');
        $deployment = json_decode($application->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $runtime = ['phpHeapMiB' => $application->phpHeapMiB(), 'phpProcessMemoryMiB' => $application->phpProcessMemoryMiB(),
            'nativeManagerHeapMiB' => $application->nativeManagerHeapMiB(), 'nativeCommandLimit' => $application->nativeCommandLimit(),
            'nativeResultLimit' => $application->nativeResultLimit(), 'nativeClientLimit' => $application->nativeClientLimit(),
            'mediaHelperLimit' => $application->mediaHelperLimit(), 'scheduledConsoleMiB' => $application->phpProcessMemoryMiB()];
        $profile = ImageProfile::fromArray(['version' => 1, 'deployment' => $deployment, 'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1], 'runtime' => $runtime]);
        $before = $profile->toArray();
        $configuration = HouseholdConfiguration::fromArray(self::configuration());
        $compose = HouseholdRenderer::render($configuration, $this->images(), ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']), $profile, 'max_connections = 20', 'owner');
        $rendered = json_decode(str_replace('$$', '$', $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE_JSON']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($profile->deployment, $rendered);
        self::assertSame([2, 0, 0], [$rendered['sharedCopySlots'], $rendered['videoEncoderSlots'], $rendered['audioEncoderSlots']]);
        self::assertSame($rendered, json_decode(HouseholdRenderer::workerEnvironment($configuration, $profile)['BAANDER_DEPLOYMENT_PROFILE_JSON'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($before, $profile->toArray());
        self::assertSame($profile->fingerprint(), $compose['services']['web']['labels']['org.baander.deployment.fingerprint']);
    }

    public function testRootlessDaemonIsRefusedBeforeRendering(): void
    {
        KillOrder::assertApplicable(['SecurityOptions' => ['name=apparmor', 'name=seccomp,profile=builtin', 'name=cgroupns']]);
        KillOrder::assertApplicable([]);
        foreach (['name=rootless', 'name=rootless,detail=1'] as $option) {
            try {
                KillOrder::assertApplicable(['SecurityOptions' => ['name=seccomp,profile=builtin', $option]]);
                self::fail('Accepted a rootless daemon.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('rootless', $error->getMessage());
            }
        }
        $directory = sys_get_temp_dir() . '/baander.app-household-' . bin2hex(random_bytes(8));
        $store = new HouseholdStateStore($directory);
        $target = new Target('/usr/bin/docker', 'unix:///run/baander.app-docker.sock', 'owned-daemon', null, null);
        $commands = [];
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $command) use (&$commands): ProcessResult {
            $arguments = array_slice($command->argv, 3);
            $commands[] = $arguments;
            $output = $arguments[0] === 'info' ? '{"ID":"owned-daemon","SecurityOptions":["name=seccomp,profile=builtin","name=rootless","name=cgroupns"]}' : '';
            return new ProcessResult(0, null, false, false, $output, '', 0);
        });
        try {
            try {
                new Household($store, new Client($executor, $target, []), $executor, [], dirname(__DIR__, 3))->render(HouseholdConfiguration::fromArray(self::configuration()));
                self::fail('Rendered for a rootless daemon.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('negative out-of-memory scores', $error->getMessage());
            }
            self::assertSame(['info'], array_column($commands, 0));
            self::assertNull($store->load());
        } finally {
            Collector::remove($directory);
        }
    }

    public function testPostgresConnectionsAreSizedFromThePoolerServerPools(): void
    {
        self::assertSame(20, Pooler::maxConnections(['application' => 10, 'control' => 4, 'direct' => 3], 3));
        self::assertSame(35, Pooler::maxConnections(['application' => 20, 'control' => 8, 'direct' => 4], 3));
        self::assertSame(9, Pooler::maxConnections(['application' => 1, 'control' => 1, 'direct' => 0], 7));
        foreach (['household' => '/deploy/household/postgresql.conf', 'full' => '/docker/postgres/conf/postgresql.conf'] as $profile => $path) {
            $configuration = HouseholdConfiguration::fromArray(array_replace(self::configuration(), ['profile' => $profile]));
            $content = file_get_contents(dirname(__DIR__, 3) . $path);
            self::assertIsString($content);
            $rendered = HouseholdRenderer::configurationFiles($configuration, $content)['postgres_configuration']['content'];
            $settings = new PostgresSettings($rendered)->expectedSettings();
            self::assertSame(Pooler::maxConnections(Pooler::POOLS[$profile], (int) $settings['superuser_reserved_connections']), (int) $settings['max_connections'], $profile);
        }
    }

    public function testConfigurationRejectsUnknownProfilesAndHostSourceMounts(): void
    {
        $data = self::configuration();
        foreach ([
            [['profile' => 'tiny'], 'Select the household or full deployment profile.'],
            [['media' => ['/home/operator/music']], 'The household profile runs fixed shared copy slots and accepts no media configuration.'],
            [['profile' => 'full', 'media' => ['/home/operator/music']], 'media must be an object.'],
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

    public function testFullPresetCarriesItsMediaSlotsIntoTheRenderedProfile(): void
    {
        $configuration = HouseholdConfiguration::fromArray(array_replace(self::configuration(), ['profile' => 'full', 'media' => ['videoSlots' => 4, 'audioSlots' => 0]]));
        self::assertSame([4, 0], [$configuration->slots('video'), $configuration->slots('audio')]);
        self::assertSame(['videoSlots' => 4, 'audioSlots' => 0], $configuration->toArray()['media']);
        self::assertEquals($configuration, HouseholdConfiguration::fromArray($configuration->toArray()));
        $partial = HouseholdConfiguration::fromArray(array_replace(self::configuration(), ['profile' => 'full', 'media' => ['audioSlots' => 6]]));
        self::assertSame([null, 6], [$partial->slots('video'), $partial->slots('audio')]);
        $unset = HouseholdConfiguration::fromArray(array_replace(self::configuration(), ['profile' => 'full']));
        self::assertSame([null, null], [$unset->slots('video'), $unset->slots('audio')]);
        self::assertArrayNotHasKey('media', $unset->toArray());

        $deployment = new DeploymentProfile('full', 4, 0);
        $profile = ImageProfile::fromArray([
            'version' => 1,
            'deployment' => json_decode($deployment->toJson(), true, flags: JSON_THROW_ON_ERROR),
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1],
            'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320, 'nativeCommandLimit' => $deployment->nativeCommandLimit()],
        ]);
        $compose = HouseholdRenderer::render($configuration, $this->images(), ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']), $profile, 'max_connections = 35', 'owner');
        $rendered = $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE_JSON'];
        self::assertEquals($deployment, DeploymentProfile::fromJson($rendered));
        self::assertEquals($deployment, DeploymentProfile::fromJson(HouseholdRenderer::workerEnvironment($configuration, $profile)['BAANDER_DEPLOYMENT_PROFILE_JSON']));
        self::assertSame($profile->fingerprint(), $compose['services']['web']['labels']['org.baander.deployment.fingerprint']);
    }

    public function testThePoolerClientLimitIsTheOneTheSlotMaximumsAreDerivedFrom(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 3) . '/docker/postgres/pooler/pgbouncer.ini');
        self::assertIsString($configuration);
        self::assertSame(1, preg_match_all('/^max_client_conn\s*=\s*(\d+)\s*$/m', $configuration, $matches));
        self::assertSame(MediaSlotBounds::POOLER_CLIENT_LIMIT, (int) $matches[1][0]);
    }

    public function testHouseholdPresetRejectsMediaSlots(): void
    {
        foreach ([['videoSlots' => 8], ['audioSlots' => 0], []] as $media) {
            try {
                HouseholdConfiguration::fromArray(array_replace(self::configuration(), ['media' => $media]));
                self::fail('Accepted media slots for the household profile.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('The household profile runs fixed shared copy slots and accepts no media configuration.', $error->getMessage());
            }
        }
    }

    public function testMediaSlotsRequireKnownKindsAndWholeNonNegativeCounts(): void
    {
        foreach ([
            [['subtitleSlots' => 1], 'Unknown key in media configuration.'],
            [['videoSlots' => -1], 'Media videoSlots must be zero or more jobs.'],
            [['audioSlots' => '12'], 'Media audioSlots must be zero or more jobs.'],
            [['videoSlots' => 2.0], 'Media videoSlots must be zero or more jobs.'],
        ] as [$media, $message]) {
            try {
                HouseholdConfiguration::fromArray(array_replace(self::configuration(), ['profile' => 'full', 'media' => $media]));
                self::fail('Accepted invalid media slots.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function testExampleConfigurationRendersForEachProfile(): void
    {
        $example = JsonObject::read(dirname(__DIR__, 3) . '/deploy/household/example.json');
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        foreach ([
            'household' => [$example, null],
            'full' => [array_replace($example, ['profile' => 'full']), null],
            'full with media' => [array_replace($example, ['profile' => 'full', 'media' => ['videoSlots' => 8, 'audioSlots' => 12]]), 8],
        ] as $case => [$data, $videoSlots]) {
            $configuration = HouseholdConfiguration::fromArray($data);
            self::assertSame($videoSlots, $configuration->slots('video'), $case);
            $compose = HouseholdRenderer::render($configuration, $this->images(), $layout, $this->profile($configuration->profile), 'max_connections = 35', 'owner');
            self::assertSame($configuration->profile, $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE'], $case);
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
        $roles = ['web' => 'application', 'postgres' => 'postgres', 'pooler' => 'postgres', 'redis' => 'redis', 'gateway' => 'gateway'];
        foreach ($compose['services'] as $name => $service) {
            self::assertSame($images[$roles[$name]]['id'], $service['image']);
            self::assertArrayNotHasKey('privileged', $service);
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
        self::assertArrayNotHasKey('DB_PASSWORD', $workerEnvironment);
        self::assertArrayNotHasKey('APP_DOMAIN', $workerEnvironment);
        self::assertSame('household', $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE']);
    }

    public function testRendererRoutesClientsThroughAPoolerThatAloneMountsItsCredentials(): void
    {
        $configuration = HouseholdConfiguration::fromArray(self::configuration());
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        $compose = HouseholdRenderer::render($configuration, $this->images(), $layout, $this->profile(), 'max_connections = 20', 'owner');
        $pooler = $compose['services']['pooler'];
        self::assertSame($this->images()['postgres']['id'], $pooler['image']);
        self::assertSame(['baander-pooler'], $pooler['entrypoint']);
        self::assertSame('postgres', $pooler['user']);
        self::assertSame(['postgres' => ['condition' => 'service_healthy']], $pooler['depends_on']);
        self::assertSame('service_healthy', $compose['services']['web']['depends_on']['pooler']['condition']);
        self::assertArrayNotHasKey('postgres', $compose['services']['web']['depends_on']);
        self::assertSame(['BAANDER_POOLER_USER' => 'baander', 'BAANDER_POOLER_DATABASE' => 'baander', 'BAANDER_POOLER_SERVER_HOST' => 'postgres',
            'BAANDER_POOLER_APPLICATION_POOL_SIZE' => '10', 'BAANDER_POOLER_CONTROL_POOL_SIZE' => '4'], $pooler['environment']);
        self::assertSame([['type' => 'volume', 'source' => 'pooler_credentials', 'target' => '/etc/baander-pooler/credentials', 'read_only' => true]], $pooler['volumes']);
        self::assertSame(['name' => 'baander-household-pooler-credentials', 'external' => true], $compose['volumes']['pooler_credentials']);
        self::assertArrayNotHasKey('pooler_credentials', HouseholdRenderer::configurationFiles($configuration, 'max_connections = 20'));
        foreach ($compose['services'] as $name => $service) {
            $sources = array_column($service['volumes'] ?? [], 'source');
            self::assertSame($name === 'pooler', in_array('pooler_credentials', $sources, true), $name);
        }
        self::assertArrayNotHasKey('DB_PASSWORD', $pooler['environment']);
        $web = $compose['services']['web']['environment'];
        self::assertSame('postgresql://baander:private-database-secret@pooler:6432/baander?serverVersion=18&charset=utf8', $web['DATABASE_URL']);
        self::assertSame('postgresql://baander_control:private-database-secret@pooler:6432/baander_control?serverVersion=18&charset=utf8', $web['DATABASE_CONTROL_URL']);
        $workers = HouseholdRenderer::workerEnvironment($configuration, $this->profile());
        self::assertSame([$web['DATABASE_URL'], $web['DATABASE_CONTROL_URL']], [$workers['DATABASE_URL'], $workers['DATABASE_CONTROL_URL']]);
        $full = HouseholdConfiguration::fromArray(array_replace(self::configuration(), ['profile' => 'full']));
        $fullPooler = HouseholdRenderer::render($full, $this->images(), $layout, $this->profile('full'), 'max_connections = 35', 'owner')['services']['pooler'];
        self::assertSame(['20', '8'], [$fullPooler['environment']['BAANDER_POOLER_APPLICATION_POOL_SIZE'], $fullPooler['environment']['BAANDER_POOLER_CONTROL_POOL_SIZE']]);
    }

    public function testPoolerCredentialsRunFromTheDatabaseImageWithTheCredentialsVolumeAndNoPasswordInArguments(): void
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
        $deployment = new Household($store, new Client($executor, $target, getenv()), $executor, getenv(), dirname(__DIR__, 3));
        try {
            new \ReflectionMethod(Household::class, 'poolerCredentials')->invoke(
                $deployment,
                HouseholdConfiguration::fromArray(self::configuration()),
                ['owner' => 'fixture-owner', 'images' => ['postgres' => ['id' => 'sha256:' . str_repeat('d', 64)]]],
            );
            self::assertInstanceOf(ProcessSpec::class, $created);
            $arguments = implode(' ', $created->argv);
            self::assertStringContainsString('--network baander-household-private', $arguments);
            self::assertStringContainsString('type=volume,src=baander-household-pooler-credentials,dst=/credentials', $arguments);
            self::assertStringContainsString('--entrypoint baander-pooler-credentials', $arguments);
            self::assertStringEndsWith('sha256:' . str_repeat('d', 64) . ' /credentials', $arguments);
            self::assertStringNotContainsString('private-database-secret', $arguments);
            self::assertSame('private-database-secret', $created->environment['PGPASSWORD']);
            self::assertSame(['baander', 'baander', 'postgres'], [$created->environment['BAANDER_POOLER_USER'], $created->environment['BAANDER_POOLER_DATABASE'], $created->environment['BAANDER_POOLER_SERVER_HOST']]);
            self::assertStringNotContainsString('secrets', $arguments);
        } finally {
            $actual->close();
            Collector::remove($directory);
        }
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
            self::assertSame('postgresql://baander:private-database-secret@pooler:6432/baander?serverVersion=18&charset=utf8', $created->environment['DATABASE_URL']);
            self::assertSame('postgresql://baander_control:private-database-secret@pooler:6432/baander_control?serverVersion=18&charset=utf8', $created->environment['DATABASE_CONTROL_URL']);
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

    private function profile(string $identifier = 'household'): ImageProfile
    {
        return ImageProfile::fromArray([
            'version' => 1,
            'deployment' => ['identifier' => $identifier, 'webWorkers' => 2, 'doctrinePoolSlots' => 4],
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1],
            'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320],
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
