<?php

declare(strict_types=1);

namespace Baander\Deployment\Tests;

use Baander\Deployment\Presets\Configuration;
use Baander\Deployment\Presets\StateStore;
use Baander\Deployment\Presets\Renderer;
use Baander\Deployment\Profiles\ImageProfile;
use Baander\Configuration\ApplicationLayout;
use Baander\Configuration\Sources\JsonDocument;
use Baander\Deployment\Preset;
use Baander\Deployment\Workers\KillOrder;
use Baander\Deployment\Database\Pooler;
use Baander\Deployment\Database\PostgresConfiguration;
use Baander\Deployment\Database\PostgresSettings;
use Baander\Containers\Docker\Client;
use Baander\Containers\Docker\Target;
use Baander\Filesystem\AtomicFile;
use Baander\Images\Inventory\Collector;
use Baander\Process\Execution\CommandExecutor;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\Process\Execution\ProcessResult;
use Baander\Process\Execution\ProcessSpec;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\HardwareEncoder;
use App\Shared\Application\DTO\MediaSlotBounds;
use App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment;
use App\Shared\Infrastructure\Worker\OutOfMemoryKillOrder;
use PHPUnit\Framework\TestCase;

final class PresetDeploymentTest extends TestCase
{
    /** @return array<string,mixed> A remux deployment of two web workers, four database slots, one consumer and two copy slots. */
    public static function configuration(): array
    {
        return [
            'version' => 2, 'name' => 'baander-preset', 'capability' => 'remux',
            'size' => ['webWorkers' => 2, 'databaseSlots' => 4, 'ordinaryConsumers' => 1, 'sharedBuffersMiB' => 128, 'workMemMiB' => 4, 'copySlots' => 2],
            'origin' => 'http://baander.app:8080',
            'listen' => ['address' => '127.0.0.1', 'port' => 8080],
            'images' => array_fill_keys(['application', 'worker', 'operator', 'postgres', 'gateway', 'redis'], 'baander/fixture:local'),
            'environment' => ['APP_SECRET' => 'private-app-secret', 'STREAM_URL_HMAC_SECRET' => 'private-stream-secret', 'AUTH_TOTP_ENCRYPTION_KEY' => 'cHJpdmF0ZS10b3RwLWVuY3J5cHRpb24ta2V5LTMyYiE=', 'DB_USERNAME' => 'baander', 'DB_PASSWORD' => 'private-database-secret', 'DB_DATABASE' => 'baander', 'REDIS_PASSWORD' => 'private-redis-secret'],
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

    public function testManagedLoggingUsesPinnedCollectorAndRetainedDedicatedVolumes(): void
    {
        $configuration = Configuration::fromArray(self::configuration());
        $target = new Target('/usr/bin/docker', 'unix:///run/owner/docker.sock', 'captured-daemon', null, null);
        $compose = Renderer::render($configuration, $this->images(), ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']), $this->profile(), 'max_connections = 21', 'owner', [
            'target' => $target->toArray(), 'credentials' => '/private/log-credentials', 'boot' => 'fresh-web-boot',
        ]);
        $agent = $compose['services']['log-agent'];
        $config = json_decode($agent['environment']['BAANDER_LOG_AGENT_CONFIG'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('captured-daemon', $config['expectedDaemonId']);
        self::assertSame('owner', $config['containerGroups'][0]['labels']['org.baander.preset.owner']);
        self::assertSame('owner', $agent['labels']['org.baander.preset.owner']);
        self::assertSame('/run/owner/docker.sock', $agent['volumes'][0]['source']);
        self::assertTrue($agent['volumes'][2]['read_only']);
        self::assertSame('/var/log/baander/web/fresh-web-boot', $compose['services']['web']['environment']['BAANDER_LOG_DIR']);
        self::assertTrue($compose['volumes']['logs']['external']);
        self::assertTrue($compose['volumes']['log_index']['external']);
        self::assertNotContains('/run/baander-log-docker.sock', array_column($compose['services']['web']['volumes'], 'target'));
    }

    public function testRenderedServicesCarryNoContainerLimitsAndRenderTheKillOrder(): void
    {
        $remux = Configuration::fromArray(self::configuration());
        $transcode = Configuration::fromArray(self::transcodeConfiguration());
        self::assertSame(['redisMaxmemoryMiB' => 192], $remux->resources);
        self::assertSame($remux->resources, $transcode->resources);
        foreach ([['resources' => ['webMemoryMiB' => 1536]], ['resources' => ['operatorMemoryMiB' => 512]], ['resources' => ['postgresMemoryMiB' => 1536]], ['controlCpuSet' => [0]], ['executionCpuSet' => [1]]] as $override) {
            try {
                Configuration::fromArray(array_replace(self::configuration(), $override));
                self::fail('Accepted a container limit or CPU set.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Unknown key', $error->getMessage());
            }
        }
        self::assertSame('remux', $remux->capability);
        self::assertSame('transcode', $transcode->capability);
        self::assertSame('baander-preset-media', $remux->volumes['media']);
        self::assertSame('private-database-secret', $remux->environment['DB_PASSWORD']);
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        $scores = ['web' => KillOrder::WEB, 'postgres' => KillOrder::POSTGRES, 'pooler' => KillOrder::POOLER, 'redis' => KillOrder::REDIS, 'gateway' => KillOrder::GATEWAY];
        foreach ([[$remux, $this->profile()], [$transcode, $this->profile('transcode')]] as [$configuration, $profile]) {
            $compose = Renderer::render($configuration, $this->images(), $layout, $profile, 'max_connections = 21', 'owner');
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
        $configuration = Configuration::fromArray(self::configuration());
        self::assertSame(['capability' => 'remux', 'webWorkers' => 2, 'doctrinePoolSlots' => 4, 'ordinaryConsumerLimit' => 1, 'sharedCopySlots' => 2,
            'videoEncoderSlots' => null, 'audioEncoderSlots' => null, 'hardware' => null], $configuration->profileSettings());
        $compose = Renderer::render($configuration, $this->images(), ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']), $profile, 'max_connections = 21', 'owner');
        $rendered = json_decode(str_replace('$$', '$', $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($profile->deployment, $rendered);
        self::assertEquals($application, DeploymentProfile::fromJson(json_encode($rendered, JSON_THROW_ON_ERROR)));
        self::assertSame([2, 0, 0], [$rendered['sharedCopySlots'], $rendered['videoEncoderSlots'], $rendered['audioEncoderSlots']]);
        self::assertSame($rendered, json_decode(Renderer::workerEnvironment($configuration, $profile)['BAANDER_DEPLOYMENT_PROFILE'], true, flags: JSON_THROW_ON_ERROR));
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
        $directory = sys_get_temp_dir() . '/baander.app-preset-' . bin2hex(random_bytes(8));
        $store = new StateStore($directory);
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
                new Preset($store, new Client($executor, $target, []), $executor, [])->render(Configuration::fromArray(self::configuration()));
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

    public function testPlaceholderSecretsAreRefusedBeforeAnyDockerCommand(): void
    {
        $data = self::configuration();
        $data['environment']['STREAM_URL_HMAC_SECRET'] = 'replace-with-a-separate-private-random-value';
        $configuration = Configuration::fromArray($data);
        $directory = sys_get_temp_dir() . '/baander.app-preset-' . bin2hex(random_bytes(8));
        $commands = [];
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $command) use (&$commands): ProcessResult {
            $commands[] = $command->argv;
            return new ProcessResult(0, null, false, false, '', '', 0);
        });
        $target = new Target('/usr/bin/docker', 'unix:///run/baander.app-docker.sock', 'owned-daemon', null, null);
        $preset = new Preset(new StateStore($directory), new Client($executor, $target, []), $executor, []);
        try {
            foreach (['render', 'start'] as $action) {
                try {
                    $preset->{$action}($configuration);
                    self::fail($action . ' accepted a placeholder secret.');
                } catch (\InvalidArgumentException $error) {
                    self::assertSame('Replace the placeholder value of STREAM_URL_HMAC_SECRET before deploying.', $error->getMessage());
                }
            }
            self::assertSame([], $commands);
        } finally {
            Collector::remove($directory);
        }
    }

    public function testPresetRequiresTheTotpEncryptionKey(): void
    {
        $data = self::configuration();
        unset($data['environment']['AUTH_TOTP_ENCRYPTION_KEY']);
        $this->expectExceptionMessage('Missing or invalid AUTH_TOTP_ENCRYPTION_KEY.');
        Configuration::fromArray($data);
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
            $configuration = Configuration::fromArray($data);
            $content = PostgresConfiguration::render($configuration->size['sharedBuffersMiB'], $configuration->size['workMemMiB'], $pools);
            $rendered = Renderer::configurationFiles($configuration, $content)['postgres_configuration']['content'];
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
        $example = Configuration::fromArray(JsonDocument::read(dirname(__DIR__, 3) . '/deploy/preset/example-16gb-transcode.json'));
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
            [['profile' => 'remux'], 'Unknown key in preset configuration.'],
            [['media' => ['videoSlots' => 8]], 'Unknown key in preset configuration.'],
            [['version' => 1], 'Unsupported preset configuration version.'],
            [['size' => ['sharedBuffersMiB' => 16] + $size], 'Deployment size requires sharedBuffersMiB between 32 and 16384.'],
            [['size' => ['workMemMiB' => 2048] + $size], 'Deployment size requires workMemMiB between 1 and 1024.'],
            [['size' => array_diff_key($size, ['copySlots' => true])], 'Deployment size requires copySlots.'],
            [['size' => array_diff_key($size, ['webWorkers' => true])], 'Deployment size requires webWorkers.'],
            [['volumes' => ['media' => '/home/operator/music']], 'Persistent storage requires named Docker volumes.'],
            [['environment' => $data['environment'] + ['BAANDER_DEPLOYMENT_PROFILE' => '{}']], 'Deployment derives environment variable BAANDER_DEPLOYMENT_PROFILE.'],
            [
                ['environment' => $data['environment'] + ['TRUSTED_PROXIES' => '192.0.2.10']],
                'Deployment derives TRUSTED_PROXIES from the gateway; declare reverse proxies in front of the gateway in listen.frontProxies.',
            ],
            [['network' => ['subnet' => '10.231.0.4/28']], 'The edge network subnet must be an IPv4 network address with a prefix of /29 or shorter.'],
            [['network' => ['subnet' => '10.231.0.0/30']], 'The edge network subnet must be an IPv4 network address with a prefix of /29 or shorter.'],
            [['network' => ['subnet' => 'fd00:baa::/64']], 'The edge network subnet must be an IPv4 network address with a prefix of /29 or shorter.'],
            [['network' => ['subnet6' => 'fd00:baa:231::4/64']], 'The edge network subnet6 must be an IPv6 network address with a prefix of /125 or shorter.'],
            [['network' => ['subnet6' => 'fd00:baa:231::/126']], 'The edge network subnet6 must be an IPv6 network address with a prefix of /125 or shorter.'],
            [['network' => ['subnet6' => '10.231.1.0/28']], 'The edge network subnet6 must be an IPv6 network address with a prefix of /125 or shorter.'],
            [['network' => ['gateway' => '10.231.0.1']], 'Unknown key in network configuration.'],
            [['network' => ['ipv6' => 'false']], 'network.ipv6 must be true or false.'],
            [
                ['network' => ['ipv6' => false, 'subnet6' => 'fd00:baa:231::/64']],
                'An IPv4-only edge network takes no network.subnet6; remove it or set network.ipv6 to true.',
            ],
            [
                ['listen' => ['address' => '127.0.0.1', 'port' => 8080, 'frontProxies' => ['192.0.2.10; allow all']]],
                'listen.frontProxies must list IP addresses or CIDR ranges.',
            ],
            [
                ['listen' => ['address' => '127.0.0.1', 'port' => 8080, 'frontProxies' => '192.0.2.10']],
                'listen.frontProxies must list IP addresses or CIDR ranges.',
            ],
        ] as [$override, $message]) {
            try {
                Configuration::fromArray(array_replace($data, $override));
                self::fail('Accepted an invalid preset configuration: ' . $message);
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function testTranscodeSizeCarriesItsMediaSlotsIntoTheRenderedProfile(): void
    {
        $configuration = Configuration::fromArray(self::transcodeConfiguration(['videoSlots' => 4, 'audioSlots' => 0]));
        self::assertSame([4, 0], [$configuration->profileSettings()['videoEncoderSlots'], $configuration->profileSettings()['audioEncoderSlots']]);
        self::assertSame(0, $configuration->profileSettings()['sharedCopySlots']);
        self::assertSame(['videoSlots' => 4, 'audioSlots' => 0], array_intersect_key($configuration->toArray()['size'], ['videoSlots' => true, 'audioSlots' => true]));
        self::assertEquals($configuration, Configuration::fromArray($configuration->toArray()));
        $partial = Configuration::fromArray(self::transcodeConfiguration(['audioSlots' => 6]));
        self::assertSame([null, 6], [$partial->profileSettings()['videoEncoderSlots'], $partial->profileSettings()['audioEncoderSlots']]);
        $unset = Configuration::fromArray(self::transcodeConfiguration());
        self::assertSame([null, null], [$unset->profileSettings()['videoEncoderSlots'], $unset->profileSettings()['audioEncoderSlots']]);
        self::assertArrayNotHasKey('videoSlots', $unset->toArray()['size']);

        $deployment = new DeploymentProfile(videoEncoderSlots: 4, audioEncoderSlots: 0);
        $profile = ImageProfile::fromArray([
            'version' => 2,
            'deployment' => json_decode($deployment->toJson(), true, flags: JSON_THROW_ON_ERROR),
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1],
            'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320, 'nativeCommandLimit' => $deployment->nativeCommandLimit()],
        ]);
        $compose = Renderer::render($configuration, $this->images(), ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']), $profile, 'max_connections = 35', 'owner');
        $rendered = $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE'];
        self::assertEquals($deployment, DeploymentProfile::fromJson($rendered));
        self::assertEquals($deployment, DeploymentProfile::fromJson(Renderer::workerEnvironment($configuration, $profile)['BAANDER_DEPLOYMENT_PROFILE']));
        self::assertSame($profile->fingerprint(), $compose['services']['web']['labels']['org.baander.deployment.fingerprint']);
    }

    public function testTranscodeCarriesItsHardwareEncoderToTheProfileAndNoComposeService(): void
    {
        $hardware = ['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128', 'group' => 485];
        $configuration = Configuration::fromArray(self::transcodeConfiguration() + ['hardware' => $hardware]);
        self::assertSame(['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128'], $configuration->hardware());
        self::assertSame(['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128'], $configuration->profileSettings()['hardware']);
        self::assertSame(485, $configuration->hardwareGroup());
        self::assertSame([null, null], [$configuration->profileSettings()['videoEncoderSlots'], $configuration->profileSettings()['audioEncoderSlots']]);
        self::assertSame($hardware, $configuration->toArray()['hardware']);
        self::assertEquals($configuration, Configuration::fromArray($configuration->toArray()));
        $derived = Configuration::fromArray(self::transcodeConfiguration() + ['hardware' => ['accelerator' => 'nvenc']]);
        self::assertSame(['accelerator' => 'nvenc', 'device' => null], $derived->hardware());
        self::assertNull($derived->hardwareGroup());

        $deployment = new DeploymentProfile(hardware: new HardwareEncoder('vaapi', '/dev/dri/renderD128'));
        $profile = ImageProfile::fromArray([
            'version' => 2,
            'deployment' => json_decode($deployment->toJson(), true, flags: JSON_THROW_ON_ERROR),
            'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1],
            'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320],
        ]);
        $compose = Renderer::render($configuration, $this->images(), ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']), $profile, 'max_connections = 35', 'owner');
        self::assertEquals($deployment, DeploymentProfile::fromJson(str_replace('$$', '$', $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE'])));
        self::assertEquals($deployment, DeploymentProfile::fromJson(Renderer::workerEnvironment($configuration, $profile)['BAANDER_DEPLOYMENT_PROFILE']));
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
                Configuration::fromArray(self::transcodeConfiguration() + ['hardware' => $hardware]);
                self::fail('Accepted an invalid hardware encoder: ' . $message);
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
        $this->expectExceptionMessage('A remux deployment runs no hardware encoder.');
        Configuration::fromArray(self::configuration() + ['hardware' => ['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128']]);
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
                Configuration::fromArray(array_replace($data, ['size' => [...$data['size'], ...$slots]]));
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
                Configuration::fromArray(self::transcodeConfiguration($size));
                self::fail('Accepted invalid size settings: ' . $message);
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function testEachShippedExampleValidatesAndRendersItsCapability(): void
    {
        $examples = glob(dirname(__DIR__, 3) . '/deploy/preset/example-*.json') ?: [];
        self::assertCount(6, $examples);
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        foreach ($examples as $path) {
            $document = JsonDocument::read($path);
            $configuration = Configuration::fromArray($document);
            self::assertStringEndsWith('-' . $configuration->capability . '.json', $path);
            // Each example spells out the dual-stack default and the edge network's subnet in both families.
            self::assertSame(['subnet' => '10.231.0.0/28', 'ipv6' => true, 'subnet6' => 'fd00:baa:231::/64'], $document['network'], $path);
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
            $compose = Renderer::render($configuration, $this->images(), $layout, $profile, $postgres, 'owner');
            self::assertEquals($deployment, DeploymentProfile::fromJson($compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE']), $path);
            self::assertSame((string) $pools['application'], $compose['services']['pooler']['environment']['BAANDER_POOLER_APPLICATION_POOL_SIZE'], $path);
            self::assertSame((string) Pooler::maxConnections($pools), new PostgresSettings($postgres)->expectedSettings()['max_connections'], $path);
        }
    }

    public function testRenderedEnvironmentsDisableTelemetryUnlessTheOperatorSetsIt(): void
    {
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        $default = Configuration::fromArray(self::configuration());
        $web = Renderer::render($default, $this->images(), $layout, $this->profile(), 'max_connections = 21', 'owner')['services']['web']['environment'];
        self::assertSame('false', $web['OTEL_ENABLED']);
        self::assertArrayNotHasKey('OTEL_EXPORTER_OTLP_ENDPOINT', $web);
        $worker = Renderer::workerEnvironment($default, $this->profile());
        self::assertSame('false', $worker['OTEL_ENABLED']);
        self::assertArrayNotHasKey('OTEL_EXPORTER_OTLP_ENDPOINT', $worker);

        $data = self::configuration();
        $data['environment'] += ['OTEL_ENABLED' => 'true', 'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector.baander.app:4318'];
        $configured = Configuration::fromArray($data);
        $web = Renderer::render($configured, $this->images(), $layout, $this->profile(), 'max_connections = 21', 'owner')['services']['web']['environment'];
        $worker = Renderer::workerEnvironment($configured, $this->profile());
        foreach ([$web, $worker] as $environment) {
            self::assertSame(['true', 'http://collector.baander.app:4318'], [$environment['OTEL_ENABLED'], $environment['OTEL_EXPORTER_OTLP_ENDPOINT']]);
        }
    }

    public function testWorkerEnvironmentIsAdmittedByTheWorkerRuntime(): void
    {
        $data = self::configuration();
        $data['environment'] += ['OTEL_ENABLED' => 'true', 'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector.baander.app:4318'];
        foreach ([self::configuration(), $data] as $configuration) {
            $worker = Renderer::workerEnvironment(Configuration::fromArray($configuration), $this->profile());
            ksort($worker);
            self::assertSame($worker, new DeploymentRuntimeEnvironment($worker)->variables);
        }
    }

    public function testWorkerEnvironmentCarriesTheVideoEncoderButNotTheSenderName(): void
    {
        $data = self::transcodeConfiguration();
        $data['environment'] += ['VIDEO_ENCODER' => 'h264_vaapi', 'MAIL_FROM_NAME' => 'Bånder'];
        $worker = Renderer::workerEnvironment(Configuration::fromArray($data), $this->profile('transcode'));
        self::assertSame('h264_vaapi', $worker['VIDEO_ENCODER']);
        self::assertArrayNotHasKey('MAIL_FROM_NAME', $worker);
        ksort($worker);
        self::assertSame($worker, new DeploymentRuntimeEnvironment($worker)->variables);
    }

    public function testWorkerEnvironmentCarriesTheConfiguredMovieMetadataToken(): void
    {
        $data = self::configuration();
        $data['environment']['TMDB_BEARER_TOKEN'] = 'fixture-tmdb-token';

        $worker = Renderer::workerEnvironment(Configuration::fromArray($data), $this->profile());

        self::assertSame('fixture-tmdb-token', $worker['TMDB_BEARER_TOKEN'] ?? null);
        ksort($worker);
        self::assertSame($worker, new DeploymentRuntimeEnvironment($worker)->variables);
    }

    public function testProcessSizeBoundsMatchTheApplicationProfile(): void
    {
        self::assertSame([
            'webWorkers' => [1, DeploymentProfile::MAXIMUM_WEB_WORKERS],
            'databaseSlots' => [1, DeploymentProfile::MAXIMUM_DOCTRINE_POOL_SLOTS],
            'ordinaryConsumers' => [1, DeploymentProfile::MAXIMUM_ORDINARY_CONSUMERS],
            'copySlots' => [1, DeploymentProfile::MAXIMUM_COPY_SLOTS],
        ], Configuration::PROCESS_BOUNDS);
        foreach (['webWorkers' => 17, 'databaseSlots' => 0, 'ordinaryConsumers' => 5, 'copySlots' => 9] as $key => $value) {
            $data = self::configuration();
            $data['size'][$key] = $value;
            try {
                Configuration::fromArray($data);
                self::fail('Accepted ' . $key . ' ' . $value . '.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($key, $error->getMessage());
            }
        }
    }

    public function testOriginRejectsUserInfoAndControlCharacters(): void
    {
        foreach (['https://operator@baander.app', "https://baander.app\n.invalid", 'https://baander.app/other'] as $origin) {
            try {
                Configuration::fromArray(array_replace(self::configuration(), ['origin' => $origin]));
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
        $configuration = Configuration::fromArray($data);
        $profile = $this->profile();
        $workerEnvironment = Renderer::workerEnvironment($configuration, $profile);
        self::assertSame($profile->deployment, json_decode($workerEnvironment['BAANDER_DEPLOYMENT_PROFILE'], true, flags: JSON_THROW_ON_ERROR));
        $images = $this->images();
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        $compose = Renderer::render($configuration, $images, $layout, $profile, 'max_connections = 36', 'owner');
        self::assertSame(['postgres', '-c', 'config_file=/etc/baander/postgresql.conf'], $compose['services']['postgres']['command']);
        self::assertSame('secret$$literal', $compose['services']['postgres']['environment']['POSTGRES_PASSWORD']);
        $gateway = Renderer::configurationFiles($configuration, 'max_connections = 36')['gateway_configuration']['content'];
        self::assertStringContainsString('$http_upgrade', $gateway);
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
        self::assertNotContains($configuration->volumes['secrets'], array_column(Renderer::mediaMounts($configuration, $layout), 'volume'));
        self::assertArrayNotHasKey('DB_PASSWORD', $workerEnvironment);
        self::assertArrayNotHasKey('APP_DOMAIN', $workerEnvironment);
        self::assertSame($profile->deployment, json_decode(str_replace('$$', '$', $compose['services']['web']['environment']['BAANDER_DEPLOYMENT_PROFILE']), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testWebTrustsExactlyTheGatewaysPinnedEdgeAddressInEachFamily(): void
    {
        $default = $this->compose(self::configuration());
        self::assertSame(['edge' => ['ipv4_address' => '10.231.0.2', 'ipv6_address' => 'fd00:baa:231::2']], $default['services']['gateway']['networks']);
        self::assertSame('10.231.0.2,fd00:baa:231::2', $default['services']['web']['environment']['TRUSTED_PROXIES']);
        self::assertTrue($default['networks']['edge']['enable_ipv6']);
        // Docker assigns dynamic addresses from each subnet's upper half only, so no container can take the gateway's.
        self::assertSame(
            [
                ['subnet' => '10.231.0.0/28', 'gateway' => '10.231.0.1', 'ip_range' => '10.231.0.8/29'],
                ['subnet' => 'fd00:baa:231::/64', 'gateway' => 'fd00:baa:231::1', 'ip_range' => 'fd00:baa:231:0:8000::/65'],
            ],
            $default['networks']['edge']['ipam']['config'],
        );

        $network = ['subnet' => '192.168.240.0/24', 'subnet6' => 'fd12:3456:789a:1::/112'];
        $configured = $this->compose(self::configuration() + ['network' => $network]);
        self::assertSame(['edge' => ['ipv4_address' => '192.168.240.2', 'ipv6_address' => 'fd12:3456:789a:1::2']], $configured['services']['gateway']['networks']);
        self::assertSame('192.168.240.2,fd12:3456:789a:1::2', $configured['services']['web']['environment']['TRUSTED_PROXIES']);
        self::assertSame(
            [
                ['subnet' => '192.168.240.0/24', 'gateway' => '192.168.240.1', 'ip_range' => '192.168.240.128/25'],
                ['subnet' => 'fd12:3456:789a:1::/112', 'gateway' => 'fd12:3456:789a:1::1', 'ip_range' => 'fd12:3456:789a:1::8000/113'],
            ],
            $configured['networks']['edge']['ipam']['config'],
        );
        self::assertSame(
            ['subnet' => '192.168.240.0/24', 'ipv6' => true, 'subnet6' => 'fd12:3456:789a:1::/112'],
            Configuration::fromArray(self::configuration() + ['network' => $network])->toArray()['network'],
        );

        // Either subnet may be set alone; the other keeps its default.
        $ipv4Only = $this->compose(self::configuration() + ['network' => ['subnet' => '192.168.240.0/24']]);
        self::assertSame('192.168.240.2,fd00:baa:231::2', $ipv4Only['services']['web']['environment']['TRUSTED_PROXIES']);
        $ipv6Only = $this->compose(self::configuration() + ['network' => ['subnet6' => 'fd12:3456:789a:1::/112']]);
        self::assertSame('10.231.0.2,fd12:3456:789a:1::2', $ipv6Only['services']['web']['environment']['TRUSTED_PROXIES']);
    }

    public function testOnlyTheGatewayAndWebJoinTheEdgeNetwork(): void
    {
        $target = new Target('/usr/bin/docker', 'unix:///run/owner/docker.sock', 'captured-daemon', null, null);
        $compose = Renderer::render(
            Configuration::fromArray(self::configuration()),
            $this->images(),
            ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']),
            $this->profile(),
            'max_connections = 21',
            'owner',
            ['target' => $target->toArray(), 'credentials' => '/private/log-credentials', 'boot' => 'fresh-web-boot'],
        );
        $networks = array_map(
            static fn(array $service): array => array_is_list($service['networks']) ? $service['networks'] : array_keys($service['networks']),
            $compose['services'],
        );

        self::assertSame(
            ['web' => ['private', 'edge'], 'postgres' => ['private'], 'pooler' => ['private'], 'redis' => ['private'], 'gateway' => ['edge'], 'log-agent' => ['private']],
            $networks,
        );
        self::assertSame('baander-preset-edge', $compose['networks']['edge']['name']);
        self::assertSame('owner', $compose['networks']['edge']['labels']['org.baander.preset.owner']);
        self::assertSame('baander-preset-private', $compose['networks']['private']['name']);
    }

    public function testStartRefusesAnEdgeSubnetThatOverlapsAnotherDockerNetwork(): void
    {
        $networks = [
            ['Name' => 'bridge', 'IPAM' => ['Config' => [['Subnet' => '172.17.0.0/16'], ['Subnet' => 'fd00:baa::/64']]]],
            ['Name' => 'host', 'IPAM' => ['Config' => []]],
            // The deployment's own edge network, left by an interrupted start.
            ['Name' => 'baander-preset-edge', 'IPAM' => ['Config' => [['Subnet' => '10.231.0.0/28'], ['Subnet' => 'fd00:baa:231::/64']]]],
        ];
        $this->assertEdgeSubnetFree(self::configuration(), $networks);

        foreach ([
            '10.0.0.0/8' => ['10.231.0.0/28', 'network.subnet'],
            '10.231.0.8/29' => ['10.231.0.0/28', 'network.subnet'],
            '10.231.0.0/24' => ['10.231.0.0/28', 'network.subnet'],
            'fd00::/8' => ['fd00:baa:231::/64', 'network.subnet6'],
            'fd00:baa:231:0:8000::/65' => ['fd00:baa:231::/64', 'network.subnet6'],
            'fd00:baa:231::/48' => ['fd00:baa:231::/64', 'network.subnet6'],
        ] as $subnet => [$edge, $setting]) {
            try {
                $this->assertEdgeSubnetFree(self::configuration(), [...$networks, ['Name' => 'baander-other', 'IPAM' => ['Config' => [['Subnet' => $subnet]]]]]);
                self::fail('Accepted an edge subnet that overlaps ' . $subnet . '.');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'Docker network baander-other uses ' . $subnet . ', which overlaps the edge subnet ' . $edge . '; set ' . $setting . ' to a free range.',
                    $error->getMessage(),
                );
            }
        }
    }

    public function testGatewaySetsEveryForwardingHeaderItself(): void
    {
        $data = self::configuration();
        $data['listen'] = ['address' => '192.0.2.40', 'port' => 8080];
        $gateway = $this->gatewayConfiguration($data);

        // Both locations, the WebSocket upgrade and everything else, replace what a client sent.
        foreach ([
            'proxy_set_header Host $http_host;',
            'proxy_set_header X-Forwarded-For $remote_addr;',
            'proxy_set_header X-Forwarded-Host $http_host;',
            'proxy_set_header X-Forwarded-Proto $forwarded_proto;',
            'proxy_set_header X-Forwarded-Port "";',
        ] as $header) {
            self::assertSame(2, substr_count($gateway, $header), $header);
        }
        self::assertStringNotContainsString('$proxy_add_x_forwarded_for', $gateway);
        // A listener on a LAN address with no declared front proxy takes no peer's word for the client.
        self::assertStringNotContainsString('set_real_ip_from', $gateway);
        self::assertMatchesRegularExpression('/geo \$realip_remote_addr \$from_front_proxy \{\s*default 0;\s*\}/', $gateway);
        self::assertMatchesRegularExpression('/map "\$from_front_proxy:\$http_x_forwarded_proto" \$forwarded_proto \{\s*default \$scheme;/', $gateway);
    }

    public function testGatewayAcceptsClientsAndReachesWebInBothFamilies(): void
    {
        $gateway = $this->gatewayConfiguration(self::configuration());

        self::assertMatchesRegularExpression('/server \{\s*listen 80;\s*listen \[::\]:80;/', $gateway);
        // Docker's DNS answers web's edge address in each family; a short validity follows a replaced web container.
        self::assertMatchesRegularExpression(
            '/upstream web \{\s*zone web \d+k;\s*resolver 127\.0\.0\.11 valid=\d+s;\s*server web:9501 resolve;\s*\}/',
            $gateway,
        );
        self::assertStringNotContainsString('ipv6=off', $gateway);
        self::assertSame(2, substr_count($gateway, 'proxy_pass http://web;'));
        self::assertStringNotContainsString('proxy_pass http://web:', $gateway);
        // Web listens on ::, whose IPv6 socket also takes the gateway's IPv4 connections.
        self::assertSame(
            ['-d', 'memory_limit=256M', '/srv/baander/bin/console', 'app:serve', '--host', '::', '--no-interaction'],
            $this->compose(self::configuration())['services']['web']['command'],
        );
    }

    public function testAnIpv4OnlyEdgeNetworkCarriesNoIpv6Address(): void
    {
        $data = self::configuration() + ['network' => ['ipv6' => false]];
        $compose = $this->compose($data);
        $gateway = $this->gatewayConfiguration($data);

        self::assertArrayNotHasKey('enable_ipv6', $compose['networks']['edge']);
        self::assertSame([['subnet' => '10.231.0.0/28', 'gateway' => '10.231.0.1', 'ip_range' => '10.231.0.8/29']], $compose['networks']['edge']['ipam']['config']);
        self::assertSame(['edge' => ['ipv4_address' => '10.231.0.2']], $compose['services']['gateway']['networks']);
        self::assertSame('10.231.0.2', $compose['services']['web']['environment']['TRUSTED_PROXIES']);
        // Web keeps the configured 0.0.0.0 listener.
        self::assertSame(['-d', 'memory_limit=256M', '/srv/baander/bin/console', 'app:serve', '--no-interaction'], $compose['services']['web']['command']);
        self::assertMatchesRegularExpression('/server \{\s*listen 80;\s*server_name _;/', $gateway);
        self::assertStringNotContainsString('[::]', $gateway);
        // Host-local clients of the loopback listener arrive from the IPv4 bridge alone.
        self::assertStringContainsString("set_real_ip_from 10.231.0.1;\nreal_ip_header X-Forwarded-For;\n", $gateway);
        self::assertMatchesRegularExpression('/geo \$realip_remote_addr \$from_front_proxy \{\s*default 0;\s*10\.231\.0\.1 1;\s*\}/', $gateway);
        self::assertSame(['subnet' => '10.231.0.0/28', 'ipv6' => false], Configuration::fromArray($data)->toArray()['network']);

        $configured = self::configuration() + ['network' => ['subnet' => '192.168.240.0/24', 'ipv6' => false]];
        self::assertSame('192.168.240.2', $this->compose($configured)['services']['web']['environment']['TRUSTED_PROXIES']);
        self::assertSame($configured['network'], Configuration::fromArray($configured)->toArray()['network']);
    }

    public function testStartNamesTheIpv4OnlySettingWhenDockerCannotCreateTheDualStackEdgeNetwork(): void
    {
        $failure = "failed to create network baander-preset-edge: Error response from daemon: unable to enable IPv6 addresses on bridge\n";
        $error = $this->startEdgeServicesFailing(self::configuration(), $failure);
        self::assertStringContainsString('Docker could not create the dual-stack edge network baander-preset-edge;', $error->getMessage());
        self::assertStringContainsString('set network.ipv6 to false', $error->getMessage());
        // Docker's own reason stays in the message.
        self::assertStringContainsString('unable to enable IPv6 addresses on bridge', $error->getMessage());

        // Another failure, or one of an IPv4-only edge network, is reported as Docker reported it.
        foreach ([[self::configuration(), "container baander-preset-web-1 is unhealthy\n"], [self::configuration() + ['network' => ['ipv6' => false]], $failure]] as [$data, $stderr]) {
            self::assertStringNotContainsString('network.ipv6', $this->startEdgeServicesFailing($data, $stderr)->getMessage());
        }
    }

    public function testDeclaredFrontProxiesAloneReportTheClientAndItsProtocol(): void
    {
        $data = self::configuration();
        $proxies = ['192.0.2.10', '198.51.100.0/24', '2001:db8:2::10', '2001:db8:1::/64'];
        $data['listen'] = ['address' => '192.0.2.40', 'port' => 8080, 'frontProxies' => $proxies];
        $configuration = Configuration::fromArray($data);
        $gateway = $this->gatewayConfiguration($data);

        self::assertSame($proxies, $configuration->toArray()['listen']['frontProxies']);
        self::assertStringContainsString(
            "set_real_ip_from 192.0.2.10;\nset_real_ip_from 198.51.100.0/24;\nset_real_ip_from 2001:db8:2::10;\nset_real_ip_from 2001:db8:1::/64;\n"
            . "real_ip_header X-Forwarded-For;\nreal_ip_recursive on;\n",
            $gateway,
        );
        // The protocol gate lists every front proxy of either family.
        self::assertMatchesRegularExpression(
            '/geo \$realip_remote_addr \$from_front_proxy \{\s*default 0;\s*192\.0\.2\.10 1;\s*198\.51\.100\.0\/24 1;\s*2001:db8:2::10 1;\s*2001:db8:1::\/64 1;\s*\}/',
            $gateway,
        );
        // A declared front proxy's HTTP or HTTPS is passed on; any other peer's header falls back to the gateway's scheme.
        self::assertMatchesRegularExpression(
            '/map "\$from_front_proxy:\$http_x_forwarded_proto" \$forwarded_proto \{\s*default \$scheme;\s*"~\^1:\(https\?\)\$" \$1;\s*\}/',
            $gateway,
        );
        // An IPv6 front proxy connects to the gateway over IPv6.
        self::assertStringContainsString('listen [::]:80;', $gateway);
        self::assertArrayNotHasKey('TRUSTED_PROXIES', $configuration->environment);
        self::assertSame('10.231.0.2,fd00:baa:231::2', $this->compose($data)['services']['web']['environment']['TRUSTED_PROXIES']);
    }

    public function testALoopbackListenerTrustsHostClientsArrivingFromEitherEdgeBridge(): void
    {
        // Host-local clients reach an IPv4 loopback port from the IPv4 bridge and an IPv6 loopback port from the IPv6 bridge.
        foreach (['127.0.0.1', '127.0.1.1', '::1', '0:0:0:0:0:0:0:1'] as $address) {
            $data = self::configuration();
            $data['listen'] = ['address' => $address, 'port' => 8080];
            $gateway = $this->gatewayConfiguration($data);
            self::assertStringContainsString("set_real_ip_from 10.231.0.1;\nset_real_ip_from fd00:baa:231::1;\nreal_ip_header X-Forwarded-For;\n", $gateway, $address);
            self::assertMatchesRegularExpression('/geo \$realip_remote_addr \$from_front_proxy \{\s*default 0;\s*10\.231\.0\.1 1;\s*fd00:baa:231::1 1;\s*\}/', $gateway, $address);
            self::assertSame(
                [['target' => 80, 'published' => '8080', 'host_ip' => $address, 'protocol' => 'tcp']],
                $this->compose($data)['services']['gateway']['ports'],
                $address,
            );
        }
        $data = self::configuration() + ['network' => ['subnet' => '192.168.240.0/24', 'subnet6' => 'fd12:3456:789a:1::/112']];
        $data['listen']['frontProxies'] = ['192.0.2.10'];
        self::assertStringContainsString(
            "set_real_ip_from 192.0.2.10;\nset_real_ip_from 192.168.240.1;\nset_real_ip_from fd12:3456:789a:1::1;\n",
            $this->gatewayConfiguration($data),
        );

        // An IPv6 listener on another address publishes there and trusts neither bridge.
        $data = self::configuration();
        $data['listen'] = ['address' => '2001:db8::40', 'port' => 8443];
        self::assertStringNotContainsString('set_real_ip_from', $this->gatewayConfiguration($data));
        self::assertSame(
            [['target' => 80, 'published' => '8443', 'host_ip' => '2001:db8::40', 'protocol' => 'tcp']],
            $this->compose($data)['services']['gateway']['ports'],
        );
    }

    public function testRendererRoutesClientsThroughAPoolerThatAloneMountsItsCredentials(): void
    {
        $configuration = Configuration::fromArray(self::configuration());
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']);
        $compose = Renderer::render($configuration, $this->images(), $layout, $this->profile(), 'max_connections = 20', 'owner');
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
        self::assertSame(['name' => 'baander-preset-pooler-credentials', 'external' => true], $compose['volumes']['pooler_credentials']);
        self::assertArrayNotHasKey('pooler_credentials', Renderer::configurationFiles($configuration, 'max_connections = 20'));
        foreach ($compose['services'] as $name => $service) {
            $sources = array_column($service['volumes'] ?? [], 'source');
            self::assertSame($name === 'pooler', in_array('pooler_credentials', $sources, true), $name);
        }
        self::assertArrayNotHasKey('DB_PASSWORD', $pooler['environment']);
        $web = $compose['services']['web']['environment'];
        self::assertSame('postgresql://baander:private-database-secret@pooler:6432/baander?serverVersion=18&charset=utf8', $web['DATABASE_URL']);
        self::assertSame('postgresql://baander_control:private-database-secret@pooler:6432/baander_control?serverVersion=18&charset=utf8', $web['DATABASE_CONTROL_URL']);
        $workers = Renderer::workerEnvironment($configuration, $this->profile());
        self::assertSame([$web['DATABASE_URL'], $web['DATABASE_CONTROL_URL']], [$workers['DATABASE_URL'], $workers['DATABASE_CONTROL_URL']]);
        $transcode = Configuration::fromArray(self::transcodeConfiguration());
        $transcodePooler = Renderer::render($transcode, $this->images(), $layout, $this->profile('transcode'), 'max_connections = 35', 'owner')['services']['pooler'];
        self::assertSame(['20', '8'], [$transcodePooler['environment']['BAANDER_POOLER_APPLICATION_POOL_SIZE'], $transcodePooler['environment']['BAANDER_POOLER_CONTROL_POOL_SIZE']]);
    }

    public function testPoolerCredentialsRunFromTheDatabaseImageWithTheCredentialsVolumeAndNoPasswordInArguments(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-preset-' . bin2hex(random_bytes(8));
        $store = new StateStore($directory);
        copy(dirname(__DIR__, 3) . '/tests/Support/Tooling/Fixtures/development-lifecycle-docker.php', $directory . '/docker');
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
        $deployment = new Preset($store, new Client($executor, $target, getenv()), $executor, getenv());
        try {
            new \ReflectionMethod(Preset::class, 'poolerCredentials')->invoke(
                $deployment,
                Configuration::fromArray(self::configuration()),
                ['owner' => 'fixture-owner', 'images' => ['postgres' => ['id' => 'sha256:' . str_repeat('d', 64)]]],
            );
            self::assertInstanceOf(ProcessSpec::class, $created);
            $arguments = implode(' ', $created->argv);
            self::assertStringContainsString('--network baander-preset-private', $arguments);
            self::assertStringContainsString('type=volume,src=baander-preset-pooler-credentials,dst=/credentials', $arguments);
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
        Renderer::render(Configuration::fromArray(self::transcodeConfiguration()), $this->images(), ApplicationLayout::load(), $this->profile(), 'max_connections = 36', 'owner');
    }

    public function testMigrationsTransferGeneratedCredentialsThroughTheRealDisposableWrapper(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-preset-' . bin2hex(random_bytes(8));
        $store = new StateStore($directory);
        copy(dirname(__DIR__, 3) . '/tests/Support/Tooling/Fixtures/development-lifecycle-docker.php', $directory . '/docker');
        chmod($directory . '/docker', 0700);
        AtomicFile::writeJson($directory . '/daemon.json', ['daemonId' => 'owned-daemon']);
        // The two disposable runs make 24 process-group Docker calls. Each holds its group for
        // the 250 ms descendant grace after exit and starts the fake Docker's own PHP, so the
        // test needs about 10 s on a developer host; the budget allows three times that.
        $actual = new EngineExecutor(ExecutionContext::bounded(30, getenv()));
        $executor = $this->createStub(CommandExecutor::class);
        $created = null;
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $command, CompletionPolicy $policy) use ($actual, &$created): ProcessResult {
            if (in_array('create', $command->argv, true)) {
                $created = $command;
            }
            return $actual->run($command, $policy);
        });
        $target = new Target($directory . '/docker', 'unix:///run/baander.app-docker.sock', 'owned-daemon', null, null);
        $configuration = Configuration::fromArray(self::configuration());
        $deployment = new Preset($store, new Client($executor, $target, getenv()), $executor, getenv());
        try {
            new \ReflectionMethod(Preset::class, 'migrate')->invoke(
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
            new \ReflectionMethod(Preset::class, 'initializeSecrets')->invoke(
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
        $this->withSavedDeployment(function (Preset $deployment, StateStore $store, array &$commands): void {
            $configuration = self::configuration();
            $configuration['volumes'] = ['media' => 'another-media-volume'];
            try {
                $deployment->start(Configuration::fromArray($configuration));
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
        $this->withSavedDeployment(function (Preset $deployment, StateStore $store, array &$commands): void {
            $result = $deployment->stop();
            self::assertSame('stopped', $result['phase']);
            self::assertSame('stopped', $store->load()['phase']);
            $down = array_values(array_filter($commands, static fn(array $arguments): bool => in_array('down', $arguments, true)));
            self::assertCount(1, $down);
            self::assertNotContains('--volumes', $down[0]);
            self::assertNotContains('-v', $down[0]);
            self::assertSame('baander-preset-media', $result['dataVolumes']['media']);
        });
    }

    public function testStopRefusesForeignComposeContainersBeforeRemovingAnything(): void
    {
        $this->withSavedDeployment(function (Preset $deployment, StateStore $store, array &$commands): void {
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
        $directory = sys_get_temp_dir() . '/baander.app-preset-' . bin2hex(random_bytes(8));
        $store = new StateStore($directory);
        $target = new Target('/usr/bin/docker', 'unix:///run/baander.app-docker.sock', 'owned-daemon', null, null);
        $commands = [];
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $command) use (&$commands, $foreign): ProcessResult {
            $arguments = array_slice($command->argv, 3);
            $commands[] = $arguments;
            $output = match ($arguments[0]) {
                'info' => '{"ID":"owned-daemon"}',
                'inspect' => json_encode([['Config' => ['Labels' => ['org.baander.preset.owner' => 'foreign']]]], JSON_THROW_ON_ERROR),
                'compose' => in_array('ps', $arguments, true) && $foreign ? 'container-id' : '',
                default => '',
            };
            return new ProcessResult($arguments[0] === 'network' ? 1 : 0, null, false, false, $output, '', 0);
        });
        AtomicFile::write($directory . '/compose-fixture.json', '{}');
        $store->save(['version' => 1, 'phase' => 'starting', 'owner' => 'owned',
            'configuration' => Configuration::fromArray(self::configuration())->toArray(),
            'target' => $target->toArray(), 'profile' => $this->profile()->toArray(), 'profileFingerprint' => $this->profile()->fingerprint(),
            'composeFile' => $directory . '/compose-fixture.json', 'composeHash' => hash('sha256', '{}'), 'configurationVolumes' => []]);
        try {
            $test(new Preset($store, new Client($executor, $target, []), $executor, []), $store, $commands);
        } finally {
            Collector::remove($directory);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function compose(array $data): array
    {
        return Renderer::render(
            Configuration::fromArray($data),
            $this->images(),
            ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']),
            $this->profile(),
            'max_connections = 21',
            'owner',
        );
    }

    /** @param array<string,mixed> $data */
    private function gatewayConfiguration(array $data): string
    {
        return Renderer::configurationFiles(Configuration::fromArray($data), 'max_connections = 21')['gateway_configuration']['content'];
    }

    /**
     * Runs the start check against a daemon whose network inventory is the given inspections.
     *
     * @param array<string,mixed> $data
     * @param list<array<string,mixed>> $networks
     */
    private function assertEdgeSubnetFree(array $data, array $networks): void
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static function (ProcessSpec $command) use ($networks): ProcessResult {
            $arguments = array_slice($command->argv, 3);
            $output = match (array_slice($arguments, 0, 2)) {
                ['network', 'ls'] => implode("\n", array_map(static fn(int $index): string => 'network-' . $index, array_keys($networks))) . "\n",
                ['network', 'inspect'] => json_encode($networks, JSON_THROW_ON_ERROR),
                default => throw new \LogicException('Unexpected Docker command: ' . implode(' ', $arguments)),
            };
            return new ProcessResult(0, null, false, false, $output, '', 0);
        });
        $target = new Target('/usr/bin/docker', 'unix:///run/baander.app-docker.sock', 'owned-daemon', null, null);
        $directory = sys_get_temp_dir() . '/baander.app-preset-' . bin2hex(random_bytes(8));
        $preset = new Preset(new StateStore($directory), new Client($executor, $target, []), $executor, []);
        try {
            new \ReflectionMethod(Preset::class, 'assertEdgeSubnetFree')->invoke($preset, Configuration::fromArray($data));
        } finally {
            Collector::remove($directory);
        }
    }

    /**
     * Runs start's last Compose step, which creates the edge network, against a daemon that fails it with
     * the given error output, and returns the error start reports.
     *
     * @param array<string,mixed> $data
     */
    private function startEdgeServicesFailing(array $data, string $stderr): \RuntimeException
    {
        $executor = $this->createStub(CommandExecutor::class);
        $executor->method('run')->willReturnCallback(static fn(ProcessSpec $command, CompletionPolicy $policy): ProcessResult => $policy->complete(
            new ProcessResult(1, null, false, false, '', $stderr, 0),
            $command,
        ));
        $target = new Target('/usr/bin/docker', 'unix:///run/baander.app-docker.sock', 'owned-daemon', null, null);
        $directory = sys_get_temp_dir() . '/baander.app-preset-' . bin2hex(random_bytes(8));
        $store = new StateStore($directory);
        AtomicFile::write($directory . '/compose-fixture.json', '{}');
        $store->save(['version' => 1, 'phase' => 'starting', 'composeFile' => $directory . '/compose-fixture.json']);
        $preset = new Preset($store, new Client($executor, $target, []), $executor, []);
        try {
            new \ReflectionMethod(Preset::class, 'startEdgeServices')->invoke($preset, Configuration::fromArray($data));
        } catch (\RuntimeException $error) {
            return $error;
        } finally {
            Collector::remove($directory);
        }
        self::fail('Start ignored a failed Compose step.');
    }

    /** @return array<string,array{id:string}> */
    private function images(): array
    {
        $images = [];
        foreach (Configuration::IMAGE_ROLES as $index => $role) {
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
        $directory = sys_get_temp_dir() . '/baander.app-preset-' . bin2hex(random_bytes(8));
        $store = new StateStore($directory);
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
