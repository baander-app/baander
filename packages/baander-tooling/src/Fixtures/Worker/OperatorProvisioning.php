<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\{Failure, Runtime as FixtureRuntime};
use Throwable;

final readonly class OperatorProvisioning
{
    public function __construct(private OperatorQualification $qualification) {}

    public function startWeb(): void
    {
        $s = $this->qualification->state;
        $s->webName = $s->runId . '-web-' . bin2hex(random_bytes(4));
        $limits = $this->qualification->state->dependencyCpu === '' ? [] : ['--cpuset-cpus', $this->qualification->state->dependencyCpu, '--cpus', '0.5'];
        $this->qualification->resources->startContainer($s->webName, [
            '--network',
            $s->runId . '-control',
            '--publish',
            '127.0.0.1:' . $this->qualification->state->webPort . ':9501',
            '--cap-drop',
            'ALL',
            '--security-opt',
            'no-new-privileges',
            '--pids-limit',
            '128',
            '--memory',
            '2g',
            '--memory-swap',
            '2g',
            ...$limits,
            '--env-file',
            $this->qualification->work('runtime.env'),
            '-e',
            'STREAM_URL_HMAC_SECRET=two-command-drill-only',
            '-e',
            'OTEL_ENABLED=false',
            '-e',
            'APP_URL=https://baander.app',
            '-e',
            'DEFAULT_URI=https://baander.app',
            '-e',
            'APP_DOMAIN=',
            ...$this->qualification->state->mediaMounts,
            '--mount',
            'type=volume,source=' . $s->secretsVolume . $this->qualification->application(',target=%APP_HOME%/config/secrets,readonly,volume-nocopy'),
            '--entrypoint',
            '/usr/local/bin/php',
            $s->imageId,
            'bin/console',
            'app:serve',
            '--no-interaction',
        ]);
        $this->qualification->docker(['network', 'connect', $s->runId, $s->webName]);
        $ready = false;
        for ($attempt = 0; $attempt < 60; ++$attempt) {
            if ($this->qualification->docker(['exec', $s->webName, 'curl', '-fsS', '-o', '/dev/null', 'http://127.0.0.1:9501/live'], false)->exitCode === 0) {
                $ready = true;
                break;
            }
            $this->qualification->commands->pause(0.5);
        }
        $this->qualification->expect($ready, 'Disposable web did not become ready');
        $this->qualification->state->webBase = 'http://' . $this->qualification->output(['port', $s->webName, '9501/tcp']);
        $this->qualification->state->webPort = substr($this->qualification->state->webBase, strrpos($this->qualification->state->webBase, ':') + 1);
    }

    public function setup(): void
    {
        $s = $this->qualification->state;
        $environment = $this->qualification->configuration->environment;
        if (($environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] ?? '0') !== '1' || !$this->qualification->configuration->container) {
            $this->qualification->expect(is_file($this->qualification->root . '/vendor/autoload.php'), 'Install Composer dependencies first');
        }
        if (!$this->qualification->configuration->container) {
            $this->qualification->commands->run([
                $environment['BAANDER_TEST_PHP_BINARY'] ?? PHP_BINARY,
                '-r',
                'foreach (["posix", "pdo_pgsql"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Host operator requires PHP extension: " . $extension . "\n"); exit(1); } }',
            ]);
        }
        $s->baseId = $this->qualification->commands->runtime->image('application')->id;
        $this->qualification->state->appHome = $this->qualification->commands->runtime->layout()->root;
        $this->qualification->docker(['info', '--format', '{{json .}}'], stdout: $this->qualification->work('host.json'));
        $host = OperatorConfiguration::json($this->qualification->work('host.json'));
        $minimum = ($this->qualification->configuration->twoCommand ? 15 : 13) + (($environment['BAANDER_TEST_AUTOSCALING_MAXIMUM'] ?? '1') === '4' ? 1 : 0);
        $this->qualification->expect(
            $host['NCPU'] >= 4 && $host['MemTotal'] >= $minimum * 1024 ** 3,
            'Worker qualification requires four CPUs and ' . $minimum . ' GiB',
        );
        $this->qualification->expect($host['CgroupVersion'] === '2', 'Worker qualification requires cgroup v2');
        $cpus = json_decode(
            $this->qualification->resources->runContainer([
                '--network',
                'none',
                '--entrypoint',
                '/usr/local/bin/php',
                $s->baseId,
                '-r',
                self::CPU_PROBE,
            ])->stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->qualification->expect(count($cpus) === 4, 'Four permitted CPUs are required');
        $s->fixtureBase = $s->baseId;
        $this->qualification->expect(
            $this->qualification->output(['image', 'inspect', '--format', '{{json (index .Config "Volumes")}}', $s->baseId]) === 'null',
            'The local fixture base must not declare volumes',
        );
        $s->imageId = $s->baseId;
        $controllerBase = $s->fixtureBase;
        if (!$this->qualification->configuration->useImage) {
            $this->buildFixture();
            $s->imageId = $this->qualification->output(['image', 'inspect', '--format', '{{.Id}}', $s->fixtureImage]);
            $controllerBase = $s->imageId;
        }
        if ($this->qualification->configuration->mediaObservation) {
            $prefix = [
                '--network',
                'none',
                '--entrypoint',
                '/usr/bin/ffmpeg',
                $s->imageId,
            ];
            $this->qualification->resources->runContainer([...$prefix, '-version'], stdout: $this->qualification->artifact('ffmpeg-version.txt'));
            $result = $this->qualification->resources->runContainer(
                [...$prefix, '-baander-invalid-option'],
                false,
                stdout: $this->qualification->artifact('ffmpeg-invalid.stdout'),
                stderr: $this->qualification->artifact('ffmpeg-invalid.stderr'),
            );
            $this->qualification->expect(
                $result->exitCode !== 0 && filesize($this->qualification->artifact('ffmpeg-version.txt')) > 0
                && filesize($this->qualification->artifact('ffmpeg-invalid.stderr')) > 0,
                'Native launch observer failed encoder negative control',
            );
        }
        if ($this->qualification->configuration->container) {
            $this->buildController($controllerBase);
        }
        foreach (['sourceVolume', 'storageVolume', 'stateVolume', 'secretsVolume', 'ipcVolume'] as $key) {
            $this->qualification->resources->createVolume($s->{$key}, ['--label', 'app.baander.test.project=' . $s->runId]);
        }
        $sourceMounts = $this->developmentSource();
        $this->qualification->state->mediaMounts = [
            '--mount',
            'type=volume,source=' . $s->sourceVolume . ',target=/media/source,readonly,volume-nocopy',
            '--mount',
            'type=volume,source=' . $s->storageVolume . $this->qualification->application(',target=%APP_HOME%/storage,volume-nocopy'),
            '--mount',
            'type=volume,source=' . $s->stateVolume . $this->qualification->application(',target=%APP_HOME%/var/transcode_state,volume-nocopy'),
        ];
        $this->qualification->resources->createNetwork($s->runId, ['--internal']);
        $this->qualification->resources->createNetwork($s->runId . '-control');
        $capacity = $this->qualification->configuration->checkpoint !== null;
        $limits = [];
        if ($capacity) {
            $this->qualification->state->dependencyCpu = (string) $cpus[3];
            $limits = ['--cpuset-cpus', $this->qualification->state->dependencyCpu, '--cpus', '0.5', '--pids-limit', '64'];
        }
        $this->qualification->resources->startContainer($s->runId . '-redis', [
            '--network',
            $s->runId,
            '--network-alias',
            'redis',
            ...$limits,
            '--memory',
            '256m',
            '--memory-swap',
            '256m',
            '-e',
            'REDIS_ARGS=--requirepass test-only',
            $this->qualification->commands->runtime->image('fixtureRedis')->id,
        ]);
        $publish = $this->qualification->configuration->container ? [] : ['--publish', '127.0.0.1::5432'];
        $this->qualification->resources->startContainer($s->runId . '-postgres', [
            '--network',
            $s->runId . '-control',
            '--network-alias',
            'postgres',
            ...$limits,
            '--memory',
            '4g',
            '--memory-swap',
            '4g',
            ...$publish,
            '-e',
            'POSTGRES_USER=baander',
            '-e',
            'POSTGRES_PASSWORD=test-only',
            '-e',
            'POSTGRES_DB=worker_operator_test',
            $this->qualification->commands->runtime->image('postgres')->id,
        ]);
        $this->qualification->docker(['network', 'connect', '--alias', 'postgres', $s->runId, $s->runId . '-postgres']);
        $ready = false;
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $postgres = $this->qualification->docker(
                [
                    'exec',
                    $s->runId . '-postgres',
                    'pg_isready',
                    '-h',
                    '127.0.0.1',
                    '-U',
                    'baander',
                    '-d',
                    'worker_operator_test',
                ],
                false,
            );
            if ($postgres->exitCode === 0) {
                $redis = $this->qualification->docker(['exec', '-e', 'REDISCLI_AUTH=test-only', $s->runId . '-redis', 'redis-cli', 'ping'], false);
                if ($redis->exitCode === 0 && trim($redis->stdout) === 'PONG') {
                    $ready = true;
                    break;
                }
            }
            $this->qualification->commands->pause(1);
        }
        $this->qualification->expect($ready, 'Disposable operator services did not become ready');
        $port = $this->qualification->configuration->container ? '5432' : $this->qualification->output(['port', $s->runId . '-postgres', '5432/tcp']);
        if (!$this->qualification->configuration->container) {
            $port = substr($port, strrpos($port, ':') + 1);
        }
        $this->createManifest($cpus, $port);
        $this->prepareMediaAndApplication();
        if ($this->qualification->configuration->container) {
            $this->qualification->resources->startContainer($s->runId . '-controller', [
                '--network',
                $s->runId . '-control',
                '--user',
                '0',
                '--cap-drop',
                'ALL',
                '--security-opt',
                'no-new-privileges',
                '--security-opt',
                'label=disable',
                '--mount',
                'type=bind,source=' . $this->qualification->topology->operatorTransport->daemonHostSocketPath . ',target=' . $this->qualification->topology->operatorTransport->containerSocketPath,
                ...$sourceMounts,
                '--entrypoint',
                '/usr/local/bin/php',
                $this->qualification->state->controllerId,
                '-r',
                'while (true) { usleep(100000); }',
            ]);
            $this->qualification->expect(
                $this->qualification->output([
                    'exec',
                    $s->runId . '-controller',
                    '/usr/local/bin/docker',
                    '--host',
                    $this->qualification->topology->operatorTarget()->endpoint,
                    'info',
                    '--format',
                    '{{.ID}}',
                ]) === $s->daemonId,
                'Controller socket reaches a different Docker daemon',
            );
            $this->qualification->docker(['exec', $s->runId . '-controller', 'mkdir', '-m', '700', '/tmp/operator']);
            OperatorConfiguration::write($this->qualification->work('target.json'), $this->qualification->topology->operatorTarget()->toArray());
            foreach (['manifest', 'credentials', 'target'] as $name) {
                $this->qualification->docker(
                    [
                        'exec',
                        '-i',
                        $s->runId . '-controller',
                        '/bin/sh',
                        '-c',
                        'umask 077; cat > "$1"',
                        'sh',
                        '/tmp/operator/' . $name . '.json',
                    ],
                    input: $this->qualification->read($this->qualification->work($name . '.json')),
                );
            }
        }
    }

    private function buildFixture(): void
    {
        $s = $this->qualification->state;
        $checkout = $this->qualification->work('checkout');
        mkdir($checkout, 0700);
        if (($this->qualification->configuration->environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] ?? '0') === '1') {
            $this->qualification->resources->createContainer($s->runId . '-source', [
                '--entrypoint',
                '/usr/local/bin/php',
                $s->baseId,
                '-r',
                'exit(0);',
            ]);
            $this->qualification->docker(['cp', $s->runId . $this->qualification->application('-source:%APP_HOME%/.'), $checkout]);
            $this->qualification->docker(['rm', $s->runId . '-source']);
            $this->qualification->commands->run(['rm', '-rf', '--', $checkout . '/config/secrets']);
        } else {
            $archive = $this->qualification->work('checkout.tar');
            $this->qualification->commands->run([
                'tar',
                '--exclude=config/secrets',
                '-cf',
                $archive,
                'vendor',
                'scripts/vendor',
                'scripts/tests/php',
                'scripts/composer.json',
                'scripts/composer.lock',
                'scripts/bootstrap.php',
                'src',
                'tests',
                'config',
                'packages',
                'migrations',
                'bin',
                'docker/general',
                'templates',
                'public',
                '.env',
                '.env.test',
                'composer.json',
                'composer.lock',
                'translations',
            ]);
            $this->qualification->commands->run(['tar', '-xf', $archive, '-C', $checkout]);
            unlink($archive);
        }
        if ($this->qualification->configuration->checkpoint?->name === 'autoscaling') {
            $this->qualification->write($checkout . '/config/services_prod.yaml', self::AUTOSCALING_SERVICES);
        }
        $this->qualification->write($this->qualification->work('Dockerfile'), self::FIXTURE_DOCKERFILE);
        $this->buildImage(
            $s->fixtureImage,
            [
                '--pull=false',
                '--build-arg',
                'FIXTURE_BASE=' . $this->qualification->resources->buildInput('fixture-base', $s->fixtureBase),
                '--build-arg',
                'MEDIA_CONTROL_OBSERVATION=' . ($this->qualification->configuration->mediaObservation ? '1' : '0'),
                $s->work,
            ],
            $this->qualification->artifact('build.log'),
        );
    }

    private function buildController(string $base): void
    {
        $s = $this->qualification->state;
        $source = $this->qualification->commands->runtime->configuration->images->role('operatorHelper')->reference;
        if ($this->qualification->docker(['image', 'inspect', $source], false)->exitCode !== 0) {
            $this->qualification->docker(['pull', $source], log: $this->qualification->artifact('cli-pull.log'));
        }
        $cli = $this->qualification->output(['image', 'inspect', '--format', '{{.Id}}', $source]);
        $s->controllerCli = $cli;
        mkdir($this->qualification->work('controller'), 0700);
        $this->qualification->write($this->qualification->work('controller/Dockerfile'), self::CONTROLLER_DOCKERFILE);
        $this->buildImage(
            $s->controllerImage,
            [
                '--pull=false',
                '--build-arg',
                'CONTROLLER_BASE=' . $this->qualification->resources->buildInput('controller-base', $base),
                '--build-arg',
                'DOCKER_CLI=' . $this->qualification->resources->buildInput('docker-cli', $s->controllerCli),
                $this->qualification->work('controller'),
            ],
            $this->qualification->artifact('controller-build.log'),
        );
        $this->qualification->state->controllerId = $this->qualification->output(['image', 'inspect', '--format', '{{.Id}}', $s->controllerImage]);
    }

    /** @param list<string> $arguments */
    private function buildImage(string $name, array $arguments, string $log): void
    {
        try {
            $this->qualification->resources->buildImage($name, $arguments, $log);
        } catch (Throwable $error) {
            if (in_array(FixtureRuntime::status($error), [124, 130, 143], true)) {
                throw $error;
            }
            throw new Failure(FixtureRuntime::failure($error), 1);
        }
    }

    /** @return list<string> */
    private function developmentSource(): array
    {
        if (!$this->qualification->configuration->developmentSource) {
            return [];
        }
        $s = $this->qualification->state;
        $s->developmentSourcePath = '/tmp/' . $s->runId . '-source';
        $this->qualification->resources->runContainer([

            '--network',
            'none',
            '--user',
            '0',
            '--security-opt',
            'label=disable',
            '--mount',
            'type=bind,source=/tmp,target=/host-tmp',
            '--entrypoint',
            '/bin/sh',
            $s->baseId,
            '-ec',
            <<<'SH'
            mkdir -m 755 "/host-tmp/$1"
            printf '%s\n' baander-source-readonly > "/host-tmp/$1/receipt.txt"
            chown www-data:www-data "/host-tmp/$1" "/host-tmp/$1/receipt.txt"
            SH,
            'sh',
            $s->runId . '-source',
        ]);
        $this->qualification->resources->runContainer([

            '--network',
            'none',
            '--volume',
            $s->developmentSourcePath . ':/source:z',
            '--entrypoint',
            '/bin/sh',
            $s->baseId,
            '-c',
            'test -r /source/receipt.txt',
        ]);
        return [
            '--mount',
            'type=bind,source=' . $s->developmentSourcePath . ',target=' . $s->developmentSourcePath
            . ',readonly,bind-propagation=rprivate',
        ];
    }

    /** @param list<int> $cpus */
    private function createManifest(array $cpus, string $port): void
    {
        $s = $this->qualification->state;
        $s->daemonId = $this->qualification->output(['info', '--format', '{{.ID}}']);
        $s->boot = bin2hex(random_bytes(16));
        $s->namespace = 'baander.app:' . $s->runId;
        $s->workerName = OperatorConfiguration::workerName($s->namespace, $s->boot);
        $s->controlName = substr($s->workerName, 0, -strlen('execution')) . 'control';
        $profile = OperatorConfiguration::json($this->qualification->root . '/tests/Fixtures/Worker/autoscaling-profile.json');
        $value = $this->qualification->configuration->environment['BAANDER_TEST_AUTOSCALING_MAXIMUM'] ?? (string) $profile['maximumConcurrency'];
        $this->qualification->expect(
            preg_match('/^[+-]?[0-9]+$/D', trim($value)) === 1 && in_array((int) $value, [1, 2, 4], true),
            'Maximum concurrency must be 1, 2 or 4',
        );
        $maximum = (int) $value;
        $profile['maximumConcurrency'] = $maximum;
        $manifest = [
            'version' => 5,
            'sourceMountPolicy' => $this->qualification->configuration->developmentSource ? 'local-development' : 'production',
            'namespace' => $s->namespace,
            'bootId' => $s->boot,
            'daemonId' => $s->daemonId,
            'imageId' => $s->imageId,
            'network' => $s->runId,
            'dockerBinary' => $this->qualification->configuration->container ? '/usr/local/bin/docker' : $this->qualification->topology->operatorTarget()->executable,
            'dockerEndpoint' => $this->qualification->topology->operatorTarget()->endpoint,
            'controlMemoryMiB' => 2944,
            'executionMemoryMiB' => 4480 + 320 * ($maximum - 1),
            'controlNanoCpus' => 1_000_000_000,
            'executionNanoCpus' => 2_000_000_000,
            'controlPidsLimit' => 129,
            'executionPidsLimit' => 256 + $maximum - 1,
            'controlCpuSet' => array_slice($cpus, 0, 1),
            'executionCpuSet' => array_slice($cpus, 1, 2),
            'autoscaling' => $profile,
            'scheduledConsoleMiB' => 320,
            'ipcVolume' => $s->ipcVolume,
            'mediaMounts' => [
                [
                    'volume' => $s->sourceVolume,
                    'target' => '/media/source',
                    'readOnly' => true,
                    'role' => 'source',
                ],
                [
                    'volume' => $s->storageVolume,
                    'target' => $this->qualification->application('%APP_HOME%/storage'),
                    'readOnly' => false,
                    'role' => 'derived',
                ],
                [
                    'volume' => $s->stateVolume,
                    'target' => $this->qualification->application('%APP_HOME%/var/transcode_state'),
                    'readOnly' => false,
                    'role' => 'derived',
                ],
            ],
        ];
        if ($this->qualification->configuration->developmentSource) {
            $manifest['mediaMounts'][0] = [
                'source' => $s->developmentSourcePath,
                'target' => '/media/source',
                'readOnly' => true,
                'role' => 'source',
            ];
        }
        $runtime = [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'APP_SECRET' => 'operator-test-only',
            'DATABASE_URL' => 'postgresql://baander:test-only@postgres:5432/worker_operator_test?serverVersion=18&charset=utf8',
            'REDIS_URL' => 'redis://default:test-only@redis:6379',
            'REDIS_PASSWORD' => 'test-only',
            'MESSENGER_TRANSPORT_DSN' => 'redis://default:test-only@redis:6379/messages',
            'CONVERT_STORAGE_PATH' => $this->qualification->application('%APP_HOME%/storage/conversions'),
            'MEDIA_STORAGE_PATH' => '/media/source',
        ];
        $host = $this->qualification->configuration->container ? 'postgres' : '127.0.0.1';
        OperatorConfiguration::write($this->qualification->work('manifest.json'), $manifest);
        OperatorConfiguration::write(
            $this->qualification->work('credentials.json'),
            [
                'controllerDatabaseUrl' => 'postgresql://baander:test-only@' . $host . ':' . $port . '/worker_operator_test?serverVersion=18&charset=utf8',
                'runtimeEnvironment' => $runtime,
            ],
        );
        $lines = [];
        foreach (['APP_HOME' => $s->appHome, ...$runtime] as $key => $value) {
            $lines[] = $key . '=' . $value;
        }
        $this->qualification->write($this->qualification->work('runtime.env'), implode("\n", $lines) . "\n");
        chmod($this->qualification->work('runtime.env'), 0600);
    }

    private function prepareMediaAndApplication(): void
    {
        $s = $this->qualification->state;
        $preparation = self::APP_PREPARATION;
        if ($this->qualification->configuration->twoCommand) {
            $preparation .= "\n" . self::TWO_COMMAND_PREPARATION;
        }
        $this->qualification->resources->runContainer([

            '--user',
            'root',
            '--network',
            'none',
            '--mount',
            'type=volume,source=' . $s->sourceVolume . ',target=/media/source,volume-nocopy',
            '--mount',
            'type=volume,source=' . $s->storageVolume . $this->qualification->application(',target=%APP_HOME%/storage,volume-nocopy'),
            '--mount',
            'type=volume,source=' . $s->stateVolume . $this->qualification->application(',target=%APP_HOME%/var/transcode_state,volume-nocopy'),
            '--mount',
            'type=volume,source=' . $s->secretsVolume . ',target=/scratch-secrets,volume-nocopy',
            '--mount',
            'type=volume,source=' . $s->ipcVolume . ',target=/run/baander-worker,volume-nocopy',
            '--entrypoint',
            '/bin/sh',
            $s->imageId,
            '-ec',
            $this->qualification->application(self::MEDIA_PREPARATION),
        ]);
        $this->qualification->resources->runContainer(
            [

                '--network',
                $s->runId,
                '--env-file',
                $this->qualification->work('runtime.env'),
                '--mount',
                'type=volume,source=' . $s->sourceVolume . ',target=/media/source,volume-nocopy',
                '--mount',
                'type=volume,source=' . $s->storageVolume . $this->qualification->application(',target=%APP_HOME%/storage,volume-nocopy'),
                '--mount',
                'type=volume,source=' . $s->stateVolume . $this->qualification->application(',target=%APP_HOME%/var/transcode_state,volume-nocopy'),
                '--mount',
                'type=volume,source=' . $s->secretsVolume . $this->qualification->application(',target=%APP_HOME%/config/secrets,readonly,volume-nocopy'),
                '-e',
                'BAANDER_TEST_TWO_COMMAND=' . ($this->qualification->configuration->twoCommand ? '1' : '0'),
                '-e',
                'BAANDER_TEST_MOVIE_ENDPOINT=' . ($this->qualification->configuration->movieEndpoint ? '1' : '0'),
                '--entrypoint',
                '/bin/sh',
                $s->imageId,
                '-ec',
                $preparation,
                'sh',
                $s->namespace,
                $s->boot,
            ],
            log: $this->qualification->artifact('prepare.log'),
        );
    }

    private const string AUTOSCALING_SERVICES = <<<'YAML'
services:
    App\Tests\Fixtures\Worker\Autoscaling\WorkloadCodec:
        autowire: false
        autoconfigure: false
        tags: ['baander.message_payload_codec']
    App\Tests\Fixtures\Worker\Autoscaling\WorkloadHandler:
        autowire: false
        autoconfigure: false
        tags: [{name: messenger.message_handler}]
YAML;

    private const string FIXTURE_DOCKERFILE = <<<'DOCKERFILE'
ARG FIXTURE_BASE=scratch
FROM ${FIXTURE_BASE}
USER root
RUN rm -rf %APP_HOME%/src %APP_HOME%/tests %APP_HOME%/config %APP_HOME%/packages %APP_HOME%/migrations %APP_HOME%/bin %APP_HOME%/templates %APP_HOME%/public %APP_HOME%/vendor
COPY --chown=www-data:www-data checkout/ %APP_HOME%/
RUN composer dump-autoload --dev --optimize --no-interaction --no-scripts --no-plugins
ARG MEDIA_CONTROL_OBSERVATION=0
RUN if [ "$MEDIA_CONTROL_OBSERVATION" = 1 ]; then \
        mkdir -p /usr/lib/baander-fixture && mv /usr/bin/ffmpeg /usr/lib/baander-fixture/ffmpeg \
        && cp %APP_HOME%/tests/Fixtures/Worker/media-control-ffmpeg.php /usr/bin/ffmpeg \
        && chmod 755 /usr/bin/ffmpeg; \
    fi
RUN rm -rf %APP_HOME%/var/cache && mkdir -p %APP_HOME%/var/cache %APP_HOME%/var/log && chown -R www-data:www-data %APP_HOME%/var
RUN rm -rf %APP_HOME%/config/secrets && mkdir -p %APP_HOME%/config/secrets/oauth \
    && openssl genrsa -out %APP_HOME%/config/secrets/oauth/private.key 2048 2>/dev/null \
    && openssl rsa -in %APP_HOME%/config/secrets/oauth/private.key -pubout -out %APP_HOME%/config/secrets/oauth/public.key 2>/dev/null \
    && chown -R www-data:www-data %APP_HOME%/config/secrets
ENV APP_ENV=prod APP_DEBUG=0 XDEBUG_MODE=off
WORKDIR %APP_HOME%
USER www-data
HEALTHCHECK NONE
ENTRYPOINT ["/usr/local/bin/php"]
CMD []
DOCKERFILE;

    private const string CONTROLLER_DOCKERFILE = <<<'DOCKERFILE'
ARG CONTROLLER_BASE=scratch
ARG DOCKER_CLI=scratch
FROM ${DOCKER_CLI} AS dockercli
FROM ${CONTROLLER_BASE}
USER root
COPY --from=dockercli /usr/local/bin/docker /usr/local/bin/docker
RUN file /usr/local/bin/docker | grep -Eq 'statically linked|static-pie linked' \
    && /usr/local/bin/docker --version \
    && php -r 'foreach (["posix", "pdo_pgsql"] as $extension) { if (!extension_loaded($extension)) { exit(1); } }'
DOCKERFILE;

    private const string CPU_PROBE = <<<'PHP_PROBE'
$status = file_get_contents('/proc/self/status');
if ($status === false || preg_match('/^Cpus_allowed_list:\s+(.+)$/m', $status, $match) !== 1) {
    exit(1);
}
$cpus = [];
foreach (explode(',', trim($match[1])) as $range) {
    $limits = explode('-', $range)
        |> (static fn (array $values): array => array_map(intval(...), $values));
    foreach (range($limits[0], $limits[1] ?? $limits[0]) as $cpu) {
        $cpus[] = $cpu;
    }
}
if (count($cpus) < 4) {
    exit(1);
}
echo json_encode(array_slice($cpus, 0, 4), JSON_THROW_ON_ERROR);
PHP_PROBE;

    private const string MEDIA_PREPARATION = <<<'SH'
mkdir -p %APP_HOME%/storage/media %APP_HOME%/storage/conversions /scratch-secrets/oauth
openssl genrsa -out /scratch-secrets/oauth/private.key 2048 2>/dev/null
openssl rsa -in /scratch-secrets/oauth/private.key -pubout -out /scratch-secrets/oauth/public.key 2>/dev/null
chown -R www-data:www-data /media/source %APP_HOME%/storage %APP_HOME%/var/transcode_state /scratch-secrets /run/baander-worker
chmod 700 /scratch-secrets /scratch-secrets/oauth
chmod 600 /scratch-secrets/oauth/private.key /scratch-secrets/oauth/public.key
SH;

    private const string APP_PREPARATION = <<<'SH'
php -d memory_limit=512M bin/console cache:clear --env=prod --no-debug
php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=prod
php -d memory_limit=256M tests/Fixtures/Worker/worker-command-check.php seed-outbox "$1" "$2"
php -d memory_limit=256M tests/Fixtures/Worker/worker-command-check.php seed-scheduler "$1" "$2"
SH;

    private const string TWO_COMMAND_PREPARATION = <<<'SH'
printf '%s\n' 'Two-command-drill-only-123!' | php bin/console app:user:create --no-interaction --password --role super-admin --force two-command-admin@baander.app 'Two command admin'
php bin/console app:auth:setup-clients --no-interaction
php bin/console app:settings:set transcode.enabled true --no-interaction
php tests/Fixtures/TwoCommand/deployment.php prepare
SH;
}
