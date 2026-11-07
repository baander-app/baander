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
use Baander\Tooling\Deployment\PostgresConfiguration;
use Baander\Tooling\Deployment\PostgresSettings;
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\{CommandExecutor, CompletionPolicy, EngineExecutor, ExecutionContext, ProcessResult, ProcessSpec};
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\HardwareEncoder;
use App\Shared\Application\DTO\MediaSlotBounds;
use App\Shared\Infrastructure\Worker\OutOfMemoryKillOrder;
use PHPUnit\Framework\TestCase;

final class HouseholdDeploymentTest extends TestCase
{
    /** @return array<string,mixed> A remux deployment of two web workers, four database slots, one consumer and two copy slots. */
    public static function configuration(): array
    {
        return [
            'version' => 2, 'name' => 'baander-household', 'capability' => 'remux',
            'size' => ['webWorkers' => 2, 'databaseSlots' => 4, 'ordinaryConsumers' => 1, 'sharedBuffersMiB' => 128, 'workMemMiB' => 4, 'copySlots' => 2],
            'origin' => 'http://baander.app:8080',
            'listen' => ['address' => '127.0.0.1', 'port' => 8080],
            'images' => array_fill_keys(['application', 'worker', 'operator', 'postgres', 'gateway', 'redis'], 'baander/fixture:local'),
            'environment' => ['APP_SECRET' => 'private-app-secret', 'STREAM_URL_HMAC_SECRET' => 'private-stream-secret', 'DB_USERNAME' => 'baander', 'DB_PASSWORD' => 'private-database-secret', 'DB_DATABASE' => 'baander', 'REDIS_PASSWORD' => 'private-redis-secret'],
        ];
    }

    /**
     * A transcode deployment of four web workers, ten database slots and four consumers.
     *
     * @param array<string,int> $size
     * @return array<string,mixed>
     */
    public static function transcodeConfiguration(array $size = []): array
    {
        return array_replace(self::configuration(), ['capability' => 'transcode',
            'size' => [...['webWorkers' => 4, 'databaseSlots' => 10, 'ordinaryConsumers' => 4, 'sharedBuffersMiB' => 256, 'workMemMiB' => 16], ...$size]]);
    }

    public function testRenderedServicesCarryNoContainerLimitsAndRenderTheKillOrder(): void
    {
        $remux = HouseholdConfiguration::fromArray(self::configuration());
        $transcode = HouseholdConfiguration::fromArray(self::transcodeConfiguration());
        self::assertSame(['redisMaxmemoryMiB' => 192], $remux->resources);
        self::assertSame($remux->resources, $transcode->resources);
        foreach ([['resources' => ['webMemoryMiB' => 1536]], ['resources' => ['operatorMemoryMiB' => 512]], ['resources' => ['postgresMemoryMiB' => 1536]], ['controlCpuSet' => [0]], ['executionCpuSet' => [1]]] as $override) {
            try {
                HouseholdConfiguration::fromArray(array_replace(self::configuration(), $override));
                self::fail('Accepted a container limit or CPU set.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Unknown key', $error->getMessage());
            }
        }
        self::assertSame('remux', $remux->capability);
        self::assertSame('transcode', $transcode->capability);
        self::assertSame('baander-household-media', $remux->volumes['media']);
        self::assertSame('private-database-secret', $remux->environment['DB_PASSWORD']);
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        $scores = ['web' => KillOrder::WEB, 'postgres' => KillOrder::POSTGRES, 'pooler' => KillOrder::POOLER, 'redis' => KillOrder::REDIS, 'gateway' => KillOrder::GATEWAY];
        foreach ([[$remux, $this->profile()], [$transcode, $this->profile('transcode')]] as [$configuration, $profile]) {
            $compose = HouseholdRenderer::render($configuration, $this->images(), $layout, $profile, 'max_connections = 21', 'owner');
            self::assertSame(array_keys($scores), array_keys($compose['services']));
            foreach ($compose['services'] as $name => $service) {
                foreach (['mem_limit', 'memswap_limit', 'mem_reservation', 'pids_limit', 'cpus', 'cpu_shares', 'cpuset', 'cpu_quota', 'cpu_period', 'deploy'] as $limit) {
                    self::assertArrayNotHasKey($limit, $service, $configuration->capability . ' ' . $name . ' ' . $limit);
                }
                self::assertSame($scores[$name], $service['oom_score_adj'], $configuration->capability . ' ' . $name);
                self::assertSame($configuration->capability, $service['labels']['org.baander.deployment.capability'], $name);
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

    public function testRemuxRendersItsSharedCopyProfileAndNativeLimitsUnchanged(): void
    {
        $application = new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2);
        $deployment = json_decode($application->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $runtime = ['phpHeapMiB' => $application->phpHeapMiB(), 'phpProcessMemoryMiB' => $application->phpProcessMemoryMiB(),
            'nativeManagerHeapMiB' => $application->nativeManagerHeapMiB(), 'nativeCommandLimit' => $application->nativeCommandLimit(),
            'nativeResultLimit' => $application->nativeResultLimit(), 'nativeClientLimit' => $application->nativeClientLimit(),
            'mediaHelperLimit' => $application->mediaHelperLimit(), 'scheduledConsoleMiB' => $application->phpProcessMemoryMiB()];
        $profile = ImageProfile::fromArray(['version' => 2, 'deployment' => $deployment, 'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1], 'runtime' => $runtime]);
        $before = $profile->toArray();
        $configuration = HouseholdConfiguration::fromArray(self::configuration());
        self::assertSame(['capability' => 'remux', 'webWorkers' => 2, 'doctrinePoolSlots' => 4, 'ordinaryConsumerLimit' => 1, 'sharedCopySlots' => 2,
            'videoEncoderSlots' => null, 'audioEncoderSlots' => null, 'hardware' => null], $configuration->profileSettings());
        $compose = HouseholdRenderer::render($configuration, $this->images(), ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']), $profile, 'max_connections = 21', 'owner');
        $rendered = json_decode(str_replace('$$', '$', $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($profile->deployment, $rendered);
        self::assertEquals($application, DeploymentProfile::fromJson(json_encode($rendered, JSON_THROW_ON_ERROR)));
        self::assertSame([2, 0, 0], [$rendered['sharedCopySlots'], $rendered['videoEncoderSlots'], $rendered['audioEncoderSlots']]);
        self::assertSame($rendered, json_decode(HouseholdRenderer::workerEnvironment($configuration, $profile)['BAANDER_DEPLOYMENT_PROFILE'], true, flags: JSON_THROW_ON_ERROR));
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
                new Household($store, new Client($executor, $target, []), $executor, [])->render(HouseholdConfiguration::fromArray(self::configuration()));
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

    public function testPoolsAndConnectionsAreDerivedFromTheDeploymentSize(): void
    {
        $remux = Pooler::pools($this->profile()->deployment);
        self::assertSame(['application' => 10, 'control' => 4, 'direct' => 4], $remux);
        self::assertSame(21, Pooler::maxConnections($remux));
        $transcode = Pooler::pools($this->profile('transcode')->deployment);
        self::assertSame(['application' => 20, 'control' => 8, 'direct' => 4], $transcode);
        self::assertSame(35, Pooler::maxConnections($transcode));
        self::assertSame($transcode, Pooler::defaultPools());

        foreach ([[self::configuration(), $remux, 21], [self::transcodeConfiguration(), $transcode, 35]] as [$data, $pools, $connections]) {
            $configuration = HouseholdConfiguration::fromArray($data);
            $content = PostgresConfiguration::render($configuration->size['sharedBuffersMiB'], $configuration->size['workMemMiB'], $pools);
            $rendered = HouseholdRenderer::configurationFiles($configuration, $content)['postgres_configuration']['content'];
            $settings = new PostgresSettings($rendered)->expectedSettings();
            self::assertSame((string) $connections, $settings['max_connections'], $configuration->capability);
            self::assertSame((string) Pooler::SUPERUSER_SLOTS, $settings['superuser_reserved_connections'], $configuration->capability);
        }
    }

    public function testPostgresMemoryIsDerivedFromSharedBuffers(): void
    {
        foreach ([128 => ['64MB', '32MB', '512MB'], 1024 => ['256MB', '128MB', '4096MB']] as $sharedBuffers => [$maintenance, $autovacuum, $cache]) {
            $settings = new PostgresSettings(PostgresConfiguration::render($sharedBuffers, 8, Pooler::defaultPools()))->expectedSettings();
            self::assertSame(
                [$sharedBuffers . 'MB', '8MB', $maintenance, $autovacuum, $cache],
                [$settings['shared_buffers'], $settings['work_mem'], $settings['maintenance_work_mem'], $settings['autovacuum_work_mem'], $settings['effective_cache_size']],
            );
        }
    }

    public function testDevelopmentDatabaseConfigurationIsTheRenderedDefaultDeployment(): void
    {
        $example = HouseholdConfiguration::fromArray(JsonObject::read(dirname(__DIR__, 3) . '/deploy/household/example-16gb-transcode.json'));
        self::assertSame(
            PostgresConfiguration::render($example->size['sharedBuffersMiB'], $example->size['workMemMiB'], Pooler::defaultPools()),
            file_get_contents(dirname(__DIR__, 3) . '/docker/postgres/conf/postgresql.conf'),
        );
        $compose = file_get_contents(dirname(__DIR__, 3) . '/docker-compose.yml');
        self::assertIsString($compose);
        foreach (['APPLICATION' => 'application', 'CONTROL' => 'control'] as $variable => $pool) {
            self::assertSame(1, preg_match_all('/^\s+BAANDER_POOLER_' . $variable . '_POOL_SIZE:\s*"?(\d+)"?\s*$/m', $compose, $matches), $variable);
            self::assertSame(Pooler::defaultPools()[$pool], (int) $matches[1][0], $variable);
        }
    }

    public function testConfigurationRejectsRetiredKeysOutOfRangeSizesAndHostSourceMounts(): void
    {
        $data = self::configuration();
        $size = $data['size'];
        foreach ([
            [['capability' => 'tiny'], 'Select the transcode or remux deployment capability.'],
            [['profile' => 'household'], 'Unknown key in household configuration.'],
            [['media' => ['videoSlots' => 8]], 'Unknown key in household configuration.'],
            [['version' => 1], 'Unsupported household configuration version.'],
            [['size' => ['sharedBuffersMiB' => 16] + $size], 'Deployment size requires sharedBuffersMiB between 32 and 16384.'],
            [['size' => ['workMemMiB' => 2048] + $size], 'Deployment size requires workMemMiB between 1 and 1024.'],
            [['size' => array_diff_key($size, ['copySlots' => true])], 'Deployment size requires copySlots.'],
            [['size' => array_diff_key($size, ['webWorkers' => true])], 'Deployment size requires webWorkers.'],
            [['volumes' => ['media' => '/home/operator/music']], 'Persistent storage requires named Docker volumes.'],
            [['environment' => $data['environment'] + ['BAANDER_DEPLOYMENT_PROFILE' => '{}']], 'Deployment derives environment variable BAANDER_DEPLOYMENT_PROFILE.'],
        ] as [$override, $message]) {
            try {
                HouseholdConfiguration::fromArray(array_replace($data, $override));
                self::fail('Accepted an invalid household configuration: ' . $message);
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function testTranscodeSizeCarriesItsMediaSlotsIntoTheRenderedProfile(): void
    {
        $configuration = HouseholdConfiguration::fromArray(self::transcodeConfiguration(['videoSlots' => 4, 'audioSlots' => 0]));
        self::assertSame([4, 0], [$configuration->profileSettings()['videoEncoderSlots'], $configuration->profileSettings()['audioEncoderSlots']]);
        self::assertSame(0, $configuration->profileSettings()['sharedCopySlots']);
        self::assertSame(['videoSlots' => 4, 'audioSlots' => 0], array_intersect_key($configuration->toArray()['size'], ['videoSlots' => true, 'audioSlots' => true]));
        self::assertEquals($configuration, HouseholdConfiguration::fromArray($configuration->toArray()));
        $partial = HouseholdConfiguration::fromArray(self::transcodeConfiguration(['audioSlots' => 6]));
        self::assertSame([null, 6], [$partial->profileSettings()['videoEncoderSlots'], $partial->profileSettings()['audioEncoderSlots']]);
        $unset = HouseholdConfiguration::fromArray(self::transcodeConfiguration());
        self::assertSame([null, null], [$unset->profileSettings()['videoEncoderSlots'], $unset->profileSettings()['audioEncoderSlots']]);
        self::assertArrayNotHasKey('videoSlots', $unset->toArray()['size']);

        $deployment = new DeploymentProfile(videoEncoderSlots: 4, audioEncoderSlots: 0);
        $profile = ImageProfile::fromArray([
            'version' => 2,
            'deployment' => json_decode($deployment->toJson(), true, flags: JSON_THROW_ON_ERROR),
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1],
            'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320, 'nativeCommandLimit' => $deployment->nativeCommandLimit()],
        ]);
        $compose = HouseholdRenderer::render($configuration, $this->images(), ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']), $profile, 'max_connections = 35', 'owner');
        $rendered = $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE'];
        self::assertEquals($deployment, DeploymentProfile::fromJson($rendered));
        self::assertEquals($deployment, DeploymentProfile::fromJson(HouseholdRenderer::workerEnvironment($configuration, $profile)['BAANDER_DEPLOYMENT_PROFILE']));
        self::assertSame($profile->fingerprint(), $compose['services']['web']['labels']['org.baander.deployment.fingerprint']);
    }

    public function testTranscodeCarriesItsHardwareEncoderToTheProfileAndNoComposeService(): void
    {
        $hardware = ['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128', 'group' => 485];
        $configuration = HouseholdConfiguration::fromArray(self::transcodeConfiguration() + ['hardware' => $hardware]);
        self::assertSame(['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128'], $configuration->hardware());
        self::assertSame(['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128'], $configuration->profileSettings()['hardware']);
        self::assertSame(485, $configuration->hardwareGroup());
        self::assertSame([null, null], [$configuration->profileSettings()['videoEncoderSlots'], $configuration->profileSettings()['audioEncoderSlots']]);
        self::assertSame($hardware, $configuration->toArray()['hardware']);
        self::assertEquals($configuration, HouseholdConfiguration::fromArray($configuration->toArray()));
        $derived = HouseholdConfiguration::fromArray(self::transcodeConfiguration() + ['hardware' => ['accelerator' => 'nvenc']]);
        self::assertSame(['accelerator' => 'nvenc', 'device' => null], $derived->hardware());
        self::assertNull($derived->hardwareGroup());

        $deployment = new DeploymentProfile(hardware: new HardwareEncoder('vaapi', '/dev/dri/renderD128'));
        $profile = ImageProfile::fromArray([
            'version' => 2,
            'deployment' => json_decode($deployment->toJson(), true, flags: JSON_THROW_ON_ERROR),
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1],
            'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320],
        ]);
        $compose = HouseholdRenderer::render($configuration, $this->images(), ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']), $profile, 'max_connections = 35', 'owner');
        self::assertEquals($deployment, DeploymentProfile::fromJson(str_replace('$$', '$', $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE'])));
        self::assertEquals($deployment, DeploymentProfile::fromJson(HouseholdRenderer::workerEnvironment($configuration, $profile)['BAANDER_DEPLOYMENT_PROFILE']));
        // Only the execution worker, which the worker lifecycle creates, receives the device.
        foreach ($compose['services'] as $name => $service) {
            foreach (['devices', 'group_add', 'gpus', 'device_cgroup_rules', 'privileged', 'cap_add'] as $field) {
                self::assertArrayNotHasKey($field, $service, $name . ' must not receive ' . $field);
            }
            foreach (['cpus', 'cpu_shares', 'cpuset', 'mem_limit', 'memswap_limit', 'pids_limit', 'deploy'] as $limit) {
                self::assertArrayNotHasKey($limit, $service, $name . ' must not carry ' . $limit);
            }
        }
        self::assertNotSame($this->profile('transcode')->fingerprint(), $compose['services']['web']['labels']['org.baander.deployment.fingerprint']);
    }

    public function testHardwareEncoderConfigurationRequiresItsShapeAndATranscodeDeployment(): void
    {
        foreach ([
            ['vaapi', 'hardware must be an object.'],
            [['device' => '/dev/dri/renderD128'], 'Missing or invalid accelerator.'],
            [['accelerator' => 'vaapi', 'device' => 128], 'Missing or invalid device.'],
            [['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128', 'group' => -1], 'The hardware encoder group must be a numeric group ID.'],
            [['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128', 'group' => '485'], 'The hardware encoder group must be a numeric group ID.'],
            [['accelerator' => 'vaapi', 'devices' => ['/dev/dri/renderD128']], 'Unknown key in hardware encoder configuration.'],
        ] as [$hardware, $message]) {
            try {
                HouseholdConfiguration::fromArray(self::transcodeConfiguration() + ['hardware' => $hardware]);
                self::fail('Accepted an invalid hardware encoder: ' . $message);
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
        $this->expectExceptionMessage('A remux deployment runs no hardware encoder.');
        HouseholdConfiguration::fromArray(self::configuration() + ['hardware' => ['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128']]);
    }

    public function testThePoolerClientLimitIsTheOneTheSlotMaximumsAreDerivedFrom(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 3) . '/docker/postgres/pooler/pgbouncer.ini');
        self::assertIsString($configuration);
        self::assertSame(1, preg_match_all('/^max_client_conn\s*=\s*(\d+)\s*$/m', $configuration, $matches));
        self::assertSame(MediaSlotBounds::POOLER_CLIENT_LIMIT, (int) $matches[1][0]);
    }

    public function testEachCapabilityRefusesTheOtherCapabilitysMediaSlots(): void
    {
        foreach ([
            [self::configuration(), ['videoSlots' => 8], 'Unknown key in deployment size for remux.'],
            [self::configuration(), ['audioSlots' => 0], 'Unknown key in deployment size for remux.'],
            [self::transcodeConfiguration(), ['copySlots' => 2], 'Unknown key in deployment size for transcode.'],
        ] as [$data, $slots, $message]) {
            try {
                HouseholdConfiguration::fromArray(array_replace($data, ['size' => [...$data['size'], ...$slots]]));
                self::fail('Accepted another capability\'s media slots: ' . json_encode($slots));
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function testSizeSettingsRequireKnownKeysAndWholeNonNegativeCounts(): void
    {
        foreach ([
            [['subtitleSlots' => 1], 'Unknown key in deployment size for transcode.'],
            [['videoSlots' => -1], 'Deployment size videoSlots must be a non-negative integer.'],
            [['audioSlots' => '12'], 'Deployment size audioSlots must be a non-negative integer.'],
            [['videoSlots' => 2.0], 'Deployment size videoSlots must be a non-negative integer.'],
            [['webWorkers' => '4'], 'Deployment size webWorkers must be a non-negative integer.'],
        ] as [$size, $message]) {
            try {
                HouseholdConfiguration::fromArray(self::transcodeConfiguration($size));
                self::fail('Accepted invalid size settings: ' . $message);
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function testEachShippedExampleValidatesAndRendersItsCapability(): void
    {
        $examples = glob(dirname(__DIR__, 3) . '/deploy/household/example-*.json') ?: [];
        self::assertCount(6, $examples);
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        foreach ($examples as $path) {
            $configuration = HouseholdConfiguration::fromArray(JsonObject::read($path));
            self::assertStringEndsWith('-' . $configuration->capability . '.json', $path);
            $settings = $configuration->profileSettings();
            $deployment = new DeploymentProfile(
                $settings['capability'],
                $settings['webWorkers'],
                $settings['doctrinePoolSlots'],
                $settings['ordinaryConsumerLimit'],
                $settings['sharedCopySlots'],
                $settings['videoEncoderSlots'],
                $settings['audioEncoderSlots'],
            );
            $profile = ImageProfile::fromArray(['version' => 2, 'deployment' => json_decode($deployment->toJson(), true, flags: JSON_THROW_ON_ERROR),
                'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1], 'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320]]);
            $pools = Pooler::pools($profile->deployment);
            $postgres = PostgresConfiguration::render($configuration->size['sharedBuffersMiB'], $configuration->size['workMemMiB'], $pools);
            $compose = HouseholdRenderer::render($configuration, $this->images(), $layout, $profile, $postgres, 'owner');
            self::assertEquals($deployment, DeploymentProfile::fromJson($compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE']), $path);
            self::assertSame((string) $pools['application'], $compose['services']['pooler']['environment']['BAANDER_POOLER_APPLICATION_POOL_SIZE'], $path);
            self::assertSame((string) Pooler::maxConnections($pools), new PostgresSettings($postgres)->expectedSettings()['max_connections'], $path);
        }
    }

    public function testRenderedEnvironmentsDisableTelemetryUnlessTheOperatorSetsIt(): void
    {
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        $default = HouseholdConfiguration::fromArray(self::configuration());
        $web = HouseholdRenderer::render($default, $this->images(), $layout, $this->profile(), 'max_connections = 21', 'owner')['services']['web']['environment'];
        self::assertSame('false', $web['OTEL_ENABLED']);
        self::assertArrayNotHasKey('OTEL_EXPORTER_OTLP_ENDPOINT', $web);
        $worker = HouseholdRenderer::workerEnvironment($default, $this->profile());
        self::assertSame('false', $worker['OTEL_ENABLED']);
        self::assertArrayNotHasKey('OTEL_EXPORTER_OTLP_ENDPOINT', $worker);

        $data = self::configuration();
        $data['environment'] += ['OTEL_ENABLED' => 'true', 'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector.baander.app:4318'];
        $configured = HouseholdConfiguration::fromArray($data);
        $web = HouseholdRenderer::render($configured, $this->images(), $layout, $this->profile(), 'max_connections = 21', 'owner')['services']['web']['environment'];
        $worker = HouseholdRenderer::workerEnvironment($configured, $this->profile());
        foreach ([$web, $worker] as $environment) {
            self::assertSame(['true', 'http://collector.baander.app:4318'], [$environment['OTEL_ENABLED'], $environment['OTEL_EXPORTER_OTLP_ENDPOINT']]);
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
        self::assertSame($profile->deployment, json_decode($workerEnvironment['BAANDER_DEPLOYMENT_PROFILE'], true, flags: JSON_THROW_ON_ERROR));
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
        self::assertSame($profile->deployment, json_decode(str_replace('$$', '$', $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE']), true, flags: JSON_THROW_ON_ERROR));
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
        $transcode = HouseholdConfiguration::fromArray(self::transcodeConfiguration());
        $transcodePooler = HouseholdRenderer::render($transcode, $this->images(), $layout, $this->profile('transcode'), 'max_connections = 35', 'owner')['services']['pooler'];
        self::assertSame(['20', '8'], [$transcodePooler['environment']['BAANDER_POOLER_APPLICATION_POOL_SIZE'], $transcodePooler['environment']['BAANDER_POOLER_CONTROL_POOL_SIZE']]);
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
        $deployment = new Household($store, new Client($executor, $target, getenv()), $executor, getenv());
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

    public function testRendererRejectsAResolvedProfileOfAnotherCapability(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Configured and resolved deployment capabilities disagree.');
        HouseholdRenderer::render(HouseholdConfiguration::fromArray(self::transcodeConfiguration()), $this->images(), ApplicationLayout::load(), $this->profile(), 'max_connections = 36', 'owner');
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
        $deployment = new Household($store, new Client($executor, $target, getenv()), $executor, getenv());
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
            $test(new Household($store, new Client($executor, $target, []), $executor, []), $store, $commands);
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

    /** The resolved profile of the remux configuration, or of the transcode configuration with the image's slot defaults. */
    private function profile(string $capability = 'remux'): ImageProfile
    {
        $deployment = $capability === 'remux' ? new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2) : new DeploymentProfile();
        return ImageProfile::fromArray([
            'version' => 2,
            'deployment' => json_decode($deployment->toJson(), true, flags: JSON_THROW_ON_ERROR),
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
