<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\{Failure, Runtime as FixtureRuntime};
use RuntimeException;
use Throwable;

final class OperatorQualification
{
    /** @var array<string,mixed> */
    public array $state;
    public readonly OperatorConfiguration $configuration;
    public readonly OperatorCommands $commands;
    /** @var list<string> */
    private array $mediaMounts = [];
    private string $controllerId = '';
    private string $dependencyCpu = '';
    private string $webPort = '';

    /** @param array<string,string> $environment */
    public function __construct(public readonly string $root, array $environment)
    {
        $this->configuration = new OperatorConfiguration($environment);
        $this->commands = new OperatorCommands($root, $environment, $this->configuration->timeout);
        $runId = 'baander-worker-operator-' . time() . '-' . getmypid();
        $work = sys_get_temp_dir() . '/baander-worker-operator.' . bin2hex(random_bytes(8));
        $artifacts = $environment['BAANDER_TEST_ARTIFACTS'] ?? $root . '/var/test-runs/' . $runId;
        if (!str_starts_with($artifacts, '/')) {
            $artifacts = $root . '/' . $artifacts;
        }
        if (!is_dir($artifacts) && !mkdir($artifacts, 0700, true)) {
            throw new RuntimeException('Cannot create worker qualification artifacts');
        }
        chmod($artifacts, 0700);
        if (!mkdir($work, 0700)) {
            throw new RuntimeException('Cannot create worker qualification workspace');
        }
        $this->state = [
            'runId' => $runId,
            'work' => $work,
            'artifacts' => $artifacts,
            'webName' => $runId . '-web',
            'workerName' => '',
            'controlName' => '',
            'webBase' => '',
            'fixtureImage' => $runId . ':fixture',
            'fixtureBase' => $runId . ':base',
            'controllerImage' => $runId . ':controller',
            'controllerCli' => $runId . ':cli',
            'sourceVolume' => $runId . '-source-media',
            'storageVolume' => $runId . '-storage',
            'stateVolume' => $runId . '-media-state',
            'secretsVolume' => $runId . '-web-secrets',
            'ipcVolume' => $runId . '-worker-ipc',
            'twoCommand' => $this->configuration->twoCommand,
            'lifecyclePhase' => $this->configuration->lifecyclePhase,
            'mediaObservation' => $this->configuration->mediaObservation,
            'faultContainer' => '',
            'namespace' => '',
            'baseId' => '',
        ];
    }

    /** @param list<string> $arguments */
    public function docker(
        array $arguments,
        bool $check = true,
        ?string $input = null,
        ?string $log = null,
        ?string $stdout = null,
        ?string $stderr = null,
    ): \Baander\Tooling\Process\ProcessResult {
        return $this->commands->docker($arguments, $check, $input, $log, $stdout, $stderr);
    }

    /** @param list<string> $arguments */
    public function output(array $arguments): string
    {
        return trim($this->docker($arguments)->stdout);
    }
    public function work(string $name): string
    {
        return $this->state['work'] . '/' . $name;
    }
    public function artifact(string $name): string
    {
        return $this->state['artifacts'] . '/' . $name;
    }
    public function copy(string $from, string $to): void
    {
        OperatorConfiguration::require(copy($from, $to), 'Cannot copy evidence: ' . $from);
    }
    public function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read evidence: ' . $path);
        }
        return $contents;
    }
    public function write(string $path, string $value): void
    {
        OperatorConfiguration::require(file_put_contents($path, $value) !== false, 'Cannot write: ' . $path);
    }
    public function expect(bool $condition, string $message): void
    {
        OperatorConfiguration::require($condition, $message);
    }

    public function boot(string $boot, bool $rename = false): void
    {
        $this->state['boot'] = $boot;
        $manifest = OperatorConfiguration::json($this->work('manifest.json'));
        $manifest['bootId'] = $boot;
        OperatorConfiguration::write($this->work('manifest.json'), $manifest);
        $this->publishManifest();
        if ($rename) {
            $this->state['workerName'] = OperatorConfiguration::workerName($this->state['namespace'], $boot);
            $this->state['controlName'] = substr($this->state['workerName'], 0, -strlen('execution')) . 'control';
        }
    }

    public function publishManifest(): void
    {
        if ($this->configuration->container) {
            $this->docker(
                [
                    'exec',
                    '-i',
                    $this->state['runId'] . '-controller',
                    '/bin/sh',
                    '-c',
                    'umask 077; cat > /tmp/operator/manifest.json',
                ],
                input: $this->read($this->work('manifest.json')),
            );
        }
    }

    public function operator(string $action, int $expected): void
    {
        $arguments = $this->configuration->container
            ? [
                ...$this->commands->dockerPrefix,
                'exec',
                $this->state['runId'] . '-controller',
                '/usr/local/bin/php',
                '/var/www/html/bin/worker-deployment.php',
                $action,
                '/tmp/operator/manifest.json',
                '/tmp/operator/credentials.json',
            ]
            : [
                $this->configuration->environment['BAANDER_TEST_PHP_BINARY'] ?? PHP_BINARY,
                'bin/worker-deployment.php',
                $action,
                $this->work('manifest.json'),
                $this->work('credentials.json'),
            ];
        $result = $this->commands->run(
            $arguments,
            check: false,
            stdout: $this->work($action . '.json'),
            stderr: $this->work($action . '.stderr'),
        );
        $this->expect(
            $result->exitCode === $expected,
            'Operator ' . $action . ' expected exit ' . $expected . ', got ' . $result->exitCode . "\n"
            . $this->read($this->work($action . '.json'))
            . $this->read($this->work($action . '.stderr')),
        );
        $frame = OperatorConfiguration::json($this->work($action . '.json'));
        $this->expect(
            ($frame['action'] ?? null) === $action && ($frame['success'] ?? null) === ($expected === 0),
            'Operator response does not match action/outcome',
        );
    }

    /** @return array<string,mixed> */
    public function result(string $action = 'status'): array
    {
        return OperatorConfiguration::json($this->work($action . '.json'))['result'];
    }

    public function awaitCheck(string $mode, ?string $container = null): void
    {
        $container ??= str_starts_with($mode, 'ready') ? $this->state['controlName'] : $this->state['workerName'];
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $result = $this->docker(
                [
                    'exec',
                    $container,
                    'php',
                    '-d',
                    'memory_limit=64M',
                    'tests/Fixtures/Worker/worker-command-check.php',
                    $mode,
                    $this->state['namespace'],
                    $this->state['boot'],
                ],
                false,
                log: $this->artifact($mode . '.log'),
            );
            if ($result->exitCode === 0) {
                return;
            }
            if ($this->output(['inspect', '--format', '{{.State.Running}}', $this->state['workerName']]) !== 'true') {
                break;
            }
            $this->commands->pause(0.2);
        }
        try {
            $this->docker(
                ['logs', '--tail', '60', $this->state['workerName']],
                false,
                log: $this->artifact($mode . '-worker.log'),
            );
        } catch (Throwable $error) {
            fwrite(STDERR, 'Worker log collection failed: ' . $error->getMessage() . "\n");
        }
        throw new RuntimeException('Actual application worker check ' . $mode . ' failed; see ' . $this->artifact($mode . '.log'));
    }

    public function checkpoint(string $phase): void
    {
        $hook = $this->configuration->environment['BAANDER_TEST_TWO_COMMAND_CHECKPOINT'] ?? '';
        if ($hook !== '') {
            $this->commands->run(
                [$hook, $phase, $this->state['artifacts'], $this->state['webBase']],
                terminationGrace: 40,
            );
        }
    }

    public function snapshot(string $role, string $name, string $label): void
    {
        $this->docker(
            ['exec', $name, 'php', 'tests/Fixtures/TwoCommand/processes.php', $role],
            stdout: $this->artifact($label . '-processes.json'),
        );
    }

    public function hostIdentities(string $mode, string $input, string $output): void
    {
        $this->docker(
            [
                'run',
                '--rm',
                '-i',
                '--name',
                $this->state['runId'] . '-host-probe',
                '--pid',
                'host',
                '--network',
                'none',
                '--cap-drop',
                'ALL',
                '--security-opt',
                'no-new-privileges',
                '--entrypoint',
                '/usr/local/bin/php',
                $this->state['imageId'],
                'tests/Fixtures/TwoCommand/identities.php',
                $mode,
            ],
            input: $this->read($input),
            stdout: $output,
        );
    }

    public function combinedPhase(string $phase): void
    {
        $result = $this->docker(
            [
                'run',
                '--rm',
                '--name',
                $this->state['runId'] . '-client',
                '--network',
                'container:' . $this->state['webName'],
                '--cap-drop',
                'ALL',
                '--security-opt',
                'no-new-privileges',
                '--env-file',
                $this->work('runtime.env'),
                ...$this->mediaMounts,
                '--mount',
                'type=volume,source=' . $this->state['secretsVolume'] . ',target=/var/www/html/config/secrets,readonly,volume-nocopy',
                '--entrypoint',
                '/usr/local/bin/php',
                $this->state['imageId'],
                'tests/Fixtures/TwoCommand/deployment.php',
                $phase,
            ],
            false,
            stdout: $this->artifact($phase . '.stdout.log'),
            stderr: $this->artifact($phase . '.stderr'),
        );
        if ($result->exitCode !== 0) {
            $this->docker(
                ['exec', $this->state['webName'], 'cat', '/var/www/html/var/log/swoole_prod.log'],
                false,
                stdout: $this->artifact('swoole.log'),
            );
            throw new RuntimeException('Combined phase failed: ' . $phase . "\n" . $this->read($this->artifact($phase . '.stderr')));
        }
        $this->docker([
            'cp',
            $this->state['webName'] . ':/var/www/html/storage/two-command/result-' . $phase . '.json',
            $this->artifact($phase . '.json'),
        ]);
        $receipt = json_decode($this->read($this->artifact($phase . '.json')), flags: JSON_THROW_ON_ERROR);
        $this->expect(
            $receipt instanceof \stdClass && ($receipt->phase ?? null) === $phase
            && (is_int($receipt->seconds ?? null) || is_float($receipt->seconds ?? null))
            && $receipt->seconds >= 0
            && ($receipt->evidence ?? null) instanceof \stdClass,
            'Invalid phase receipt',
        );
    }

    private function startWeb(): void
    {
        $s = $this->state;
        $limits = $this->dependencyCpu === '' ? [] : ['--cpuset-cpus', $this->dependencyCpu, '--cpus', '0.5'];
        $this->docker([
            'run',
            '-d',
            '--name',
            $s['webName'],
            '--network',
            $s['runId'] . '-control',
            '--publish',
            '127.0.0.1:' . $this->webPort . ':9501',
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
            $this->work('runtime.env'),
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
            ...$this->mediaMounts,
            '--mount',
            'type=volume,source=' . $s['secretsVolume'] . ',target=/var/www/html/config/secrets,readonly,volume-nocopy',
            '--entrypoint',
            '/usr/local/bin/php',
            $s['imageId'],
            'bin/console',
            'app:serve',
            '--no-interaction',
        ]);
        $this->docker(['network', 'connect', $s['runId'], $s['webName']]);
        $ready = false;
        for ($attempt = 0; $attempt < 60; ++$attempt) {
            if ($this->docker(['exec', $s['webName'], 'curl', '-fsS', '-o', '/dev/null', 'http://127.0.0.1:9501/live'], false)->exitCode === 0) {
                $ready = true;
                break;
            }
            $this->commands->pause(0.5);
        }
        $this->expect($ready, 'Disposable web did not become ready');
        $this->state['webBase'] = 'http://' . $this->output(['port', $s['webName'], '9501/tcp']);
        $this->webPort = substr($this->state['webBase'], strrpos($this->state['webBase'], ':') + 1);
    }

    private function setup(): void
    {
        $s = &$this->state;
        $environment = $this->configuration->environment;
        if (($environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] ?? '0') !== '1' || !$this->configuration->container) {
            $this->expect(is_file($this->root . '/vendor/autoload.php'), 'Install Composer dependencies first');
        }
        if (!$this->configuration->container) {
            $this->commands->run([
                $environment['BAANDER_TEST_PHP_BINARY'] ?? PHP_BINARY,
                '-r',
                'foreach (["posix", "pdo_pgsql"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Host operator requires PHP extension: " . $extension . "\n"); exit(1); } }',
            ]);
        }
        $s['baseId'] = $this->output([
            'image',
            'inspect',
            '--format',
            '{{.Id}}',
            $environment['BAANDER_TEST_IMAGE'] ?? 'martinjuul/baander-app:latest',
        ]);
        $this->docker(['info', '--format', '{{json .}}'], stdout: $this->work('host.json'));
        $host = OperatorConfiguration::json($this->work('host.json'));
        $minimum = ($this->configuration->twoCommand ? 15 : 13) + (($environment['BAANDER_TEST_AUTOSCALING_MAXIMUM'] ?? '1') === '4' ? 1 : 0);
        $this->expect(
            $host['NCPU'] >= 4 && $host['MemTotal'] >= $minimum * 1024 ** 3,
            'Worker qualification requires four CPUs and ' . $minimum . ' GiB',
        );
        $this->expect($host['CgroupVersion'] === '2', 'Worker qualification requires cgroup v2');
        $cpus = json_decode(
            $this->output([
                'run',
                '--rm',
                '--name',
                $s['runId'] . '-host-probe',
                '--network',
                'none',
                '--entrypoint',
                '/usr/local/bin/php',
                $s['baseId'],
                '-r',
                self::CPU_PROBE,
            ]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->expect(count($cpus) === 4, 'Four permitted CPUs are required');
        $this->docker(['image', 'tag', $s['baseId'], $s['fixtureBase']]);
        $this->expect(
            $this->output(['image', 'inspect', '--format', '{{.Id}}', $s['fixtureBase']]) === $s['baseId'],
            'Fixture base identity changed',
        );
        $this->expect(
            $this->output(['image', 'inspect', '--format', '{{json (index .Config "Volumes")}}', $s['baseId']]) === 'null',
            'The local fixture base must not declare volumes',
        );
        $s['imageId'] = $s['baseId'];
        $controllerBase = $s['fixtureBase'];
        if (!$this->configuration->useImage) {
            $this->buildFixture();
            $s['imageId'] = $this->output(['image', 'inspect', '--format', '{{.Id}}', $s['fixtureImage']]);
            $controllerBase = $s['fixtureImage'];
        }
        if ($this->configuration->mediaObservation) {
            $prefix = [
                'run',
                '--rm',
                '--name',
                $s['runId'] . '-host-probe',
                '--network',
                'none',
                '--entrypoint',
                '/usr/bin/ffmpeg',
                $s['imageId'],
            ];
            $this->docker([...$prefix, '-version'], stdout: $this->artifact('ffmpeg-version.txt'));
            $result = $this->docker(
                [...$prefix, '-baander-invalid-option'],
                false,
                stdout: $this->artifact('ffmpeg-invalid.stdout'),
                stderr: $this->artifact('ffmpeg-invalid.stderr'),
            );
            $this->expect(
                $result->exitCode !== 0 && filesize($this->artifact('ffmpeg-version.txt')) > 0
                && filesize($this->artifact('ffmpeg-invalid.stderr')) > 0,
                'Native launch observer failed encoder negative control',
            );
        }
        if ($this->configuration->container) {
            $this->buildController($controllerBase);
        }
        foreach (['sourceVolume', 'storageVolume', 'stateVolume', 'secretsVolume', 'ipcVolume'] as $key) {
            $this->docker(['volume', 'create', '--label', 'app.baander.test.project=' . $s['runId'], $s[$key]]);
        }
        $sourceMounts = $this->developmentSource();
        $this->mediaMounts = [
            '--mount',
            'type=volume,source=' . $s['sourceVolume'] . ',target=/media/source,readonly,volume-nocopy',
            '--mount',
            'type=volume,source=' . $s['storageVolume'] . ',target=/var/www/html/storage,volume-nocopy',
            '--mount',
            'type=volume,source=' . $s['stateVolume'] . ',target=/var/www/html/var/transcode_state,volume-nocopy',
        ];
        $this->docker(['network', 'create', '--internal', $s['runId']]);
        $this->docker(['network', 'create', $s['runId'] . '-control']);
        $capacity = ($environment['BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT'] ?? '') !== ''
            || ($environment['BAANDER_TEST_AUTOSCALING_CHECKPOINT'] ?? '') !== '';
        $limits = [];
        if ($capacity) {
            $this->dependencyCpu = (string) $cpus[3];
            $limits = ['--cpuset-cpus', $this->dependencyCpu, '--cpus', '0.5', '--pids-limit', '64'];
        }
        $this->docker([
            'run',
            '-d',
            '--name',
            $s['runId'] . '-redis',
            '--network',
            $s['runId'],
            '--network-alias',
            'redis',
            ...$limits,
            '--memory',
            '256m',
            '--memory-swap',
            '256m',
            '-e',
            'REDIS_ARGS=--requirepass test-only',
            'redis/redis-stack-server:edge',
        ]);
        $publish = $this->configuration->container ? [] : ['--publish', '127.0.0.1::5432'];
        $this->docker([
            'run',
            '-d',
            '--name',
            $s['runId'] . '-postgres',
            '--network',
            $s['runId'] . '-control',
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
            $environment['BAANDER_TEST_POSTGRES_IMAGE'] ?? 'baander-database:latest',
        ]);
        $this->docker(['network', 'connect', '--alias', 'postgres', $s['runId'], $s['runId'] . '-postgres']);
        $ready = false;
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $postgres = $this->docker(
                [
                    'exec',
                    $s['runId'] . '-postgres',
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
                $redis = $this->docker(['exec', '-e', 'REDISCLI_AUTH=test-only', $s['runId'] . '-redis', 'redis-cli', 'ping'], false);
                if ($redis->exitCode === 0 && trim($redis->stdout) === 'PONG') {
                    $ready = true;
                    break;
                }
            }
            $this->commands->pause(1);
        }
        $this->expect($ready, 'Disposable operator services did not become ready');
        $port = $this->configuration->container ? '5432' : $this->output(['port', $s['runId'] . '-postgres', '5432/tcp']);
        if (!$this->configuration->container) {
            $port = substr($port, strrpos($port, ':') + 1);
        }
        $this->createManifest($cpus, $port);
        $this->prepareMediaAndApplication();
        if ($this->configuration->container) {
            $this->docker([
                'run',
                '-d',
                '--name',
                $s['runId'] . '-controller',
                '--network',
                $s['runId'] . '-control',
                '--user',
                '0',
                '--cap-drop',
                'ALL',
                '--security-opt',
                'no-new-privileges',
                '--security-opt',
                'label=disable',
                '--mount',
                'type=bind,source=' . ($environment['BAANDER_TEST_DOCKER_SOCKET_PATH'] ?? '/var/run/docker.sock') . ',target=/var/run/docker.sock',
                ...$sourceMounts,
                '--entrypoint',
                '/usr/local/bin/php',
                $this->controllerId,
                '-r',
                'while (true) { usleep(100000); }',
            ]);
            $this->expect(
                $this->output([
                    'exec',
                    $s['runId'] . '-controller',
                    '/usr/local/bin/docker',
                    '--host',
                    'unix:///var/run/docker.sock',
                    'info',
                    '--format',
                    '{{.ID}}',
                ]) === $s['daemonId'],
                'Controller socket reaches a different Docker daemon',
            );
            $this->docker(['exec', $s['runId'] . '-controller', 'mkdir', '-m', '700', '/tmp/operator']);
            foreach (['manifest', 'credentials'] as $name) {
                $this->docker(
                    [
                        'exec',
                        '-i',
                        $s['runId'] . '-controller',
                        '/bin/sh',
                        '-c',
                        'umask 077; cat > "$1"',
                        'sh',
                        '/tmp/operator/' . $name . '.json',
                    ],
                    input: $this->read($this->work($name . '.json')),
                );
            }
        }
    }

    private function buildFixture(): void
    {
        $s = $this->state;
        $checkout = $this->work('checkout');
        mkdir($checkout, 0700);
        if (($this->configuration->environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] ?? '0') === '1') {
            $this->docker([
                'create',
                '--name',
                $s['runId'] . '-source',
                '--entrypoint',
                '/usr/local/bin/php',
                $s['baseId'],
                '-r',
                'exit(0);',
            ]);
            $this->docker(['cp', $s['runId'] . '-source:/var/www/html/.', $checkout]);
            $this->docker(['rm', $s['runId'] . '-source']);
            $this->commands->run(['rm', '-rf', '--', $checkout . '/config/secrets']);
        } else {
            $archive = $this->work('checkout.tar');
            $this->commands->run([
                'tar',
                '--exclude=config/secrets',
                '-cf',
                $archive,
                'vendor',
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
            $this->commands->run(['tar', '-xf', $archive, '-C', $checkout]);
            unlink($archive);
        }
        if (($this->configuration->environment['BAANDER_TEST_AUTOSCALING_CHECKPOINT'] ?? '') !== '') {
            $this->write($checkout . '/config/services_prod.yaml', self::AUTOSCALING_SERVICES);
        }
        $this->write($this->work('Dockerfile'), self::FIXTURE_DOCKERFILE);
        $this->buildCommand(
            [
                'build',
                '--pull=false',
                '--build-arg',
                'FIXTURE_BASE=' . $s['fixtureBase'],
                '--build-arg',
                'MEDIA_CONTROL_OBSERVATION=' . ($this->configuration->mediaObservation ? '1' : '0'),
                '--tag',
                $s['fixtureImage'],
                $s['work'],
            ],
            $this->artifact('build.log'),
        );
    }

    private function buildController(string $base): void
    {
        $s = $this->state;
        $source = $this->configuration->environment['BAANDER_TEST_DOCKER_CLI_IMAGE'] ?? 'docker:29.7.2-cli';
        if ($this->docker(['image', 'inspect', $source], false)->exitCode !== 0) {
            $this->buildCommand(['pull', $source], $this->artifact('cli-pull.log'));
        }
        $cli = $this->output(['image', 'inspect', '--format', '{{.Id}}', $source]);
        $this->docker(['image', 'tag', $cli, $s['controllerCli']]);
        mkdir($this->work('controller'), 0700);
        $this->write($this->work('controller/Dockerfile'), self::CONTROLLER_DOCKERFILE);
        $this->buildCommand(
            [
                'build',
                '--pull=false',
                '--build-arg',
                'CONTROLLER_BASE=' . $base,
                '--build-arg',
                'DOCKER_CLI=' . $s['controllerCli'],
                '--tag',
                $s['controllerImage'],
                $this->work('controller'),
            ],
            $this->artifact('controller-build.log'),
        );
        $this->controllerId = $this->output(['image', 'inspect', '--format', '{{.Id}}', $s['controllerImage']]);
    }

    /** @param list<string> $arguments */
    private function buildCommand(array $arguments, string $log): void
    {
        try {
            $this->docker($arguments, log: $log);
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
        if (!$this->configuration->developmentSource) {
            return [];
        }
        $s = &$this->state;
        $s['developmentSourcePath'] = '/tmp/' . $s['runId'] . '-source';
        $this->docker([
            'run',
            '--rm',
            '--name',
            $s['runId'] . '-host-probe',
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
            $s['baseId'],
            '-ec',
            <<<'SH'
            mkdir -m 755 "/host-tmp/$1"
            printf '%s\n' baander-source-readonly > "/host-tmp/$1/receipt.txt"
            chown www-data:www-data "/host-tmp/$1" "/host-tmp/$1/receipt.txt"
            SH,
            'sh',
            $s['runId'] . '-source',
        ]);
        $this->docker([
            'run',
            '--rm',
            '--name',
            $s['runId'] . '-host-probe',
            '--network',
            'none',
            '--volume',
            $s['developmentSourcePath'] . ':/source:z',
            '--entrypoint',
            '/bin/sh',
            $s['baseId'],
            '-c',
            'test -r /source/receipt.txt',
        ]);
        return [
            '--mount',
            'type=bind,source=' . $s['developmentSourcePath'] . ',target=' . $s['developmentSourcePath']
            . ',readonly,bind-propagation=rprivate',
        ];
    }

    /** @param list<int> $cpus */
    private function createManifest(array $cpus, string $port): void
    {
        $s = &$this->state;
        $s['daemonId'] = $this->output(['info', '--format', '{{.ID}}']);
        $s['boot'] = bin2hex(random_bytes(16));
        $s['namespace'] = 'baander.app:' . $s['runId'];
        $s['workerName'] = OperatorConfiguration::workerName($s['namespace'], $s['boot']);
        $s['controlName'] = substr($s['workerName'], 0, -strlen('execution')) . 'control';
        $profile = OperatorConfiguration::json($this->root . '/tests/Fixtures/Worker/autoscaling-profile.json');
        $value = $this->configuration->environment['BAANDER_TEST_AUTOSCALING_MAXIMUM'] ?? (string) $profile['maximumConcurrency'];
        $this->expect(
            preg_match('/^[+-]?[0-9]+$/D', trim($value)) === 1 && in_array((int) $value, [1, 2, 4], true),
            'Maximum concurrency must be 1, 2 or 4',
        );
        $maximum = (int) $value;
        $profile['maximumConcurrency'] = $maximum;
        $manifest = [
            'version' => 5,
            'sourceMountPolicy' => $this->configuration->developmentSource ? 'local-development' : 'production',
            'namespace' => $s['namespace'],
            'bootId' => $s['boot'],
            'daemonId' => $s['daemonId'],
            'imageId' => $s['imageId'],
            'network' => $s['runId'],
            'dockerBinary' => $this->configuration->container ? '/usr/local/bin/docker' : $this->commands->dockerPrefix[0],
            'dockerEndpoint' => $this->configuration->container
                ? 'unix:///var/run/docker.sock'
                : ($this->configuration->environment['BAANDER_TEST_DOCKER_ENDPOINT'] ?? 'unix:///var/run/docker.sock'),
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
            'ipcVolume' => $s['ipcVolume'],
            'mediaMounts' => [
                [
                    'volume' => $s['sourceVolume'],
                    'target' => '/media/source',
                    'readOnly' => true,
                    'role' => 'source',
                ],
                [
                    'volume' => $s['storageVolume'],
                    'target' => '/var/www/html/storage',
                    'readOnly' => false,
                    'role' => 'derived',
                ],
                [
                    'volume' => $s['stateVolume'],
                    'target' => '/var/www/html/var/transcode_state',
                    'readOnly' => false,
                    'role' => 'derived',
                ],
            ],
        ];
        if ($this->configuration->developmentSource) {
            $manifest['mediaMounts'][0] = [
                'source' => $s['developmentSourcePath'],
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
            'CONVERT_STORAGE_PATH' => '/var/www/html/storage/conversions',
            'MEDIA_STORAGE_PATH' => '/media/source',
        ];
        $host = $this->configuration->container ? 'postgres' : '127.0.0.1';
        OperatorConfiguration::write($this->work('manifest.json'), $manifest);
        OperatorConfiguration::write(
            $this->work('credentials.json'),
            [
                'controllerDatabaseUrl' => 'postgresql://baander:test-only@' . $host . ':' . $port . '/worker_operator_test?serverVersion=18&charset=utf8',
                'runtimeEnvironment' => $runtime,
            ],
        );
        $lines = [];
        foreach ($runtime as $key => $value) {
            $lines[] = $key . '=' . $value;
        }
        $this->write($this->work('runtime.env'), implode("\n", $lines) . "\n");
        chmod($this->work('runtime.env'), 0600);
    }

    private function prepareMediaAndApplication(): void
    {
        $s = $this->state;
        $preparation = self::APP_PREPARATION;
        if ($this->configuration->twoCommand) {
            $preparation .= "\n" . self::TWO_COMMAND_PREPARATION;
        }
        $this->docker([
            'run',
            '--rm',
            '--name',
            $s['runId'] . '-media-prepare',
            '--user',
            'root',
            '--network',
            'none',
            '--mount',
            'type=volume,source=' . $s['sourceVolume'] . ',target=/media/source,volume-nocopy',
            '--mount',
            'type=volume,source=' . $s['storageVolume'] . ',target=/var/www/html/storage,volume-nocopy',
            '--mount',
            'type=volume,source=' . $s['stateVolume'] . ',target=/var/www/html/var/transcode_state,volume-nocopy',
            '--mount',
            'type=volume,source=' . $s['secretsVolume'] . ',target=/scratch-secrets,volume-nocopy',
            '--mount',
            'type=volume,source=' . $s['ipcVolume'] . ',target=/run/baander-worker,volume-nocopy',
            '--entrypoint',
            '/bin/sh',
            $s['imageId'],
            '-ec',
            self::MEDIA_PREPARATION,
        ]);
        $this->docker(
            [
                'run',
                '--rm',
                '--name',
                $s['runId'] . '-prepare',
                '--network',
                $s['runId'],
                '--env-file',
                $this->work('runtime.env'),
                '--mount',
                'type=volume,source=' . $s['sourceVolume'] . ',target=/media/source,volume-nocopy',
                '--mount',
                'type=volume,source=' . $s['storageVolume'] . ',target=/var/www/html/storage,volume-nocopy',
                '--mount',
                'type=volume,source=' . $s['stateVolume'] . ',target=/var/www/html/var/transcode_state,volume-nocopy',
                '--mount',
                'type=volume,source=' . $s['secretsVolume'] . ',target=/var/www/html/config/secrets,readonly,volume-nocopy',
                '-e',
                'BAANDER_TEST_TWO_COMMAND=' . ($this->configuration->twoCommand ? '1' : '0'),
                '-e',
                'BAANDER_TEST_MOVIE_ENDPOINT=' . ($this->configuration->movieEndpoint ? '1' : '0'),
                '--entrypoint',
                '/bin/sh',
                $s['imageId'],
                '-ec',
                $preparation,
                'sh',
                $s['namespace'],
                $s['boot'],
            ],
            log: $this->artifact('prepare.log'),
        );
    }

    private function initialChecks(): void
    {
        $s = &$this->state;
        if ($this->configuration->twoCommand) {
            $this->startWeb();
            $this->docker(
                ['exec', $s['webName'], 'cat', '/var/www/html/storage/two-command/state.json'],
                stdout: $this->artifact('catalog.json'),
            );
            if ($this->configuration->movieEndpoint) {
                mkdir($this->artifact('source'), 0700);
                foreach (['endpoint-source.json', 'endpoint-source-probe.json'] as $file) {
                    $this->docker([
                        'cp',
                        $s['webName'] . ':/var/www/html/storage/two-command/' . $file,
                        $this->artifact('source/' . $file),
                    ]);
                }
            }
            $catalog = OperatorConfiguration::json($this->artifact('catalog.json'));
            $login = [
                'email' => 'two-command-admin@baander.app',
                'password' => 'Two-command-drill-only-123!',
                'moviePublicId' => $catalog['movie'],
                'videoPublicId' => $catalog['video'],
                'expectedSeekPosition' => 30,
            ];
            if ($this->configuration->movieEndpoint) {
                $this->expect($catalog['scenario'] === 'movie-endpoint', 'Wrong movie endpoint scenario');
                $login['scenario'] = $catalog['scenario'];
                $login['endpoint'] = $catalog['endpoint'];
            }
            $this->expect(!file_exists($this->artifact('browser.json')), 'Browser credentials already exist');
            OperatorConfiguration::write($this->artifact('browser.json'), $login);
            $this->checkpoint('authenticated');
            if (!$this->configuration->movieEndpoint && !$this->capacityCheckpoint()) {
                $this->combinedPhase('absent');
                $this->snapshot('web', $s['webName'], 'web-without-worker');
            }
        }
        $this->operator('create', 0);
        $this->assertStopped();
        $this->operator('reconcile-create', 0);
        $this->assertStopped();
        $this->operator('status', 0);
        $result = $this->result();
        $this->expect(
            $result['manifestMatches'] === true && count($result['units']) === 2
            && isset($result['units']['control'], $result['units']['execution'])
            && array_all(
                $result['units'],
                static fn(array $unit): bool => is_string($unit['containerId']) && $unit['containerId'] !== '' && !$unit['startClaimed'],
            )
            && $result['retirement'] === 'none'
            && $result['reservation'] === 'none'
            && $result['readiness'] === 'not_checked'
            && $result['autoscaling'] === ['state' => 'unavailable']
            && $result['namespace'] === $s['namespace']
            && $result['bootId'] === $s['boot'],
            'Unexpected newly created operator status',
        );
        $this->operator('readiness', 1);
        $manifest = OperatorConfiguration::json($this->work('manifest.json'));
        $drift = $manifest;
        if (isset($drift['mediaMounts'][0]['source'])) {
            $drift['mediaMounts'][0]['source'] = '/tmp';
        } else {
            $drift['mediaMounts'][0]['volume'] .= '-drift';
        }
        OperatorConfiguration::write($this->work('manifest.json'), $drift);
        $this->publishManifest();
        $this->operator('status', 0);
        $this->expect($this->result()['manifestMatches'] === false, 'Changed source manifest was accepted');
        $this->operator('start', 3);
        $this->assertStopped();
        OperatorConfiguration::write($this->work('manifest.json'), $manifest);
        $this->publishManifest();
        $this->operator('start', 0);
        $this->operator('start', 3);
        $this->awaitCheck('ready');
        $this->awaitCheck('ready', $s['workerName']);
        $this->operator('readiness', 0);
        $this->expect($this->result('readiness')['readiness'] === 'ready', 'Worker did not become ready');
        if ($this->configuration->developmentSource) {
            $this->expect(
                $this->output(['exec', $s['workerName'], 'cat', '/media/source/receipt.txt']) === 'baander-source-readonly',
                'Development source receipt changed',
            );
            $this->expect(
                $this->docker(
                    ['exec', $s['workerName'], 'sh', '-c', 'echo forbidden > /media/source/baander-readonly-probe'],
                    false,
                )->exitCode !== 0,
                'Admitted development source allowed a write',
            );
            $this->docker(['inspect', $s['workerName']], stdout: $this->work('dev-inspect.json'));
            $worker = OperatorConfiguration::json($this->work('dev-inspect.json'))[0];
            $source = array_find($worker['Mounts'], static fn(array $mount): bool => $mount['Destination'] === '/media/source');
            $this->expect(
                $source !== null && $source['Type'] === 'bind' && $source['Source'] === $s['developmentSourcePath'] && !$source['RW'],
                'Development source mount recipe changed',
            );
            $this->expect(
                array_all(
                    $worker['Mounts'],
                    static fn(array $mount): bool => $mount === $source || ($mount['Type'] === 'volume' && $mount['Driver'] === 'local'),
                ),
                'Unexpected nonlocal development mount',
            );
        }
        $this->awaitCheck('verify-outbox');
        $this->awaitCheck('verify-scheduler');
        $this->operator('status', 0);
        $autoscaling = $this->result()['autoscaling'];
        $maximum = $manifest['autoscaling']['maximumConcurrency'];
        $this->expect(
            $autoscaling['state'] === 'known' && $autoscaling['authorityValid'] && $autoscaling['controllerFresh']
            && 0 <= $autoscaling['desired']
            && $autoscaling['desired'] <= $maximum
            && $autoscaling['occupied'] <= $maximum
            && $autoscaling['resourceFresh']
            && $autoscaling['resources']['state'] === 'known'
            && $autoscaling['evidence']['queueState'] === 'known',
            'Autoscaling evidence is not current and bounded',
        );
    }

    private function assertStopped(): void
    {
        foreach (['workerName', 'controlName'] as $role) {
            $this->expect(
                $this->output(['inspect', '--format', '{{.State.Status}} {{.State.Pid}}', $this->state[$role]]) === 'created 0',
                'Creation started a worker before admission',
            );
        }
    }

    private function capacityCheckpoint(): string
    {
        return $this->configuration->environment['BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT']
            ?? $this->configuration->environment['BAANDER_TEST_AUTOSCALING_CHECKPOINT'] ?? '';
    }

    public function assertRetired(bool $claimed = false): void
    {
        $result = $this->result();
        $this->expect(
            $result['manifestMatches'] === true && $result['retirement'] === 'completed' && $result['reservation'] === 'none'
            && count($result['units']) === 2
            && isset($result['units']['control'], $result['units']['execution'])
            && array_all(
                $result['units'],
                static fn(array $unit): bool => $unit['removed'] && (!$claimed || $unit['startClaimed']),
            ),
            'Exact worker pair was not retired',
        );
    }

    private function attachedChecks(): void
    {
        $s = $this->state;
        $this->combinedPhase('attach');
        $this->snapshot('web', $s['webName'], 'web-attached');
        $this->snapshot('worker', $s['workerName'], 'worker-attached');
        $snapshot = OperatorConfiguration::json($this->artifact('worker-attached-processes.json'));
        $video = array_filter(
            $snapshot['encoders'],
            static fn(array $process): bool => str_ends_with($process['arguments'][0], 'ffmpeg') && in_array('/media/source/video.mkv', $process['arguments'], true),
        );
        $this->expect(count($video) === 1, 'Concurrent attachments must have exactly one actual video encoder');
        $this->checkpoint('attached');
        if ($this->configuration->browserControls) {
            $this->checkpoint('client-controls');
        }
        $this->combinedPhase('seek-paused');
        $this->snapshot('worker', $s['workerName'], 'worker-paused');
        $this->checkpoint('sought-paused');
        $this->docker(['stop', '--time', '35', $s['webName']]);
        $this->docker(['rm', $s['webName']]);
        $this->startWeb();
        $this->snapshot('worker', $s['workerName'], 'worker-after-web-restart');
        $identities = static function (array $snapshot): array {
            $result = [];
            foreach ($snapshot['encoders'] as $process) {
                if (str_ends_with($process['arguments'][0], 'ffmpeg')) {
                    $result[] = $process['pid'] . ':' . $process['startTicks'];
                }
            }
            return array_unique($result);
        };
        $before = $identities(OperatorConfiguration::json($this->artifact('worker-paused-processes.json')));
        $after = $identities(OperatorConfiguration::json($this->artifact('worker-after-web-restart-processes.json')));
        $this->expect(
            $before !== [] && array_diff($before, $after) === [],
            'Replacing web stopped or replaced its independent encoder',
        );
        $this->snapshot('web', $s['webName'], 'web-restarted');
        $this->checkpoint('web-restarted');
        $this->combinedPhase('resume');
        $this->snapshot('worker', $s['workerName'], 'worker-before-retirement');
        $this->docker(
            ['top', $s['workerName'], '-eo', 'pid,ppid,args'],
            stdout: $this->artifact('worker-host-pids.txt'),
        );
        $this->hostIdentities(
            'capture',
            $this->artifact('worker-host-pids.txt'),
            $this->artifact('worker-host-identities.json'),
        );
        $this->docker(['inspect', $s['workerName']], stdout: $this->artifact('worker-inspect.json'));
        $worker = OperatorConfiguration::json($this->artifact('worker-inspect.json'))[0];
        $source = array_find($worker['Mounts'], static fn(array $mount): bool => $mount['Destination'] === '/media/source');
        $this->expect(
            $worker['Image'] === $s['imageId'] && count($worker['Mounts']) === 4 && !$worker['HostConfig']['Privileged']
            && array_all(
                $worker['Mounts'],
                static fn(array $mount): bool => $mount['Type'] === 'volume' && !str_contains($mount['Destination'], 'docker.sock'),
            )
            && array_any($worker['Mounts'], static fn(array $mount): bool => $mount['Destination'] === '/run/baander-worker')
            && $source !== null
            && !$source['RW']
            && array_all($worker['Mounts'], static fn(array $mount): bool => $mount === $source || $mount['RW']),
            'Admitted worker recipe changed',
        );
        $this->docker(['logs', $s['workerName']], log: $this->artifact('worker-before-retirement.log'));
    }

    public function finalDrill(): void
    {
        $s = $this->state;
        $this->docker(
            ['top', $s['workerName'], '-eo', 'pid,ppid,args'],
            stdout: $this->artifact('recovered-host-pids.txt'),
        );
        $this->hostIdentities(
            'capture',
            $this->artifact('recovered-host-pids.txt'),
            $this->artifact('recovered-host-identities.json'),
        );
        $this->combinedPhase('cancel');
        $this->docker(['stop', '--time', '5', $s['runId'] . '-redis']);
        $this->combinedPhase('dependency');
        $this->snapshot('web', $s['webName'], 'web-queue-outage');
        $this->docker(['start', $s['runId'] . '-redis']);
        $this->docker(['logs', $s['workerName']], log: $this->artifact('worker-before-final-retirement.log'));
        $this->operator('recover', 0);
        $this->hostIdentities(
            'verify',
            $this->artifact('recovered-host-identities.json'),
            $this->artifact('final-containment.json'),
        );
        $this->expect(
            $this->output(['container', 'ls', '--all', '--filter', 'name=^/' . $s['workerName'] . '$', '--format', '{{.ID}}']) === '',
            'Recovered worker survived retirement',
        );
        if ($this->configuration->mediaObservation) {
            $this->docker(
                ['exec', $s['webName'], 'php', 'tests/Fixtures/Worker/MediaControlObservation.php', 'verify'],
                stdout: $this->artifact('native-launch-summary.json'),
            );
        }
        $this->checkpoint('complete');
        $phases = [];
        foreach (glob($this->artifact('*.json')) ?: [] as $path) {
            $value = json_decode($this->read($path), true, flags: JSON_THROW_ON_ERROR);
            if (is_array($value) && isset($value['phase'])) {
                $phases[] = ['phase' => $value['phase'], 'seconds' => $value['seconds']];
            }
        }
        $entries = iterator_to_array(new \FilesystemIterator($s['artifacts']));
        $bytes = 0;
        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                $bytes += $entry->getSize();
            }
        }
        OperatorConfiguration::write(
            $this->artifact('summary.json'),
            ['phases' => $phases, 'artifactCount' => count($entries), 'artifactBytes' => $bytes],
        );
        echo "PASS: production web and admitted worker delivered decoded media, converged attachments, persisted controls, survived web replacement, recovered after containment and retired without surviving containers.\n";
    }

    public function execute(): void
    {
        $this->setup();
        $this->initialChecks();
        $s = &$this->state;
        if ($this->configuration->lifecyclePhase !== '') {
            new LifecycleCheckpoint($this)->run();
            return;
        }
        if ($this->configuration->movieEndpoint) {
            $this->checkpoint('movie-endpoint');
            $this->snapshot('worker', $s['workerName'], 'endpoint-worker-before-retirement');
            $this->docker(
                ['top', $s['workerName'], '-eo', 'pid,ppid,args'],
                stdout: $this->artifact('endpoint-worker-host-pids.txt'),
            );
            $this->hostIdentities(
                'capture',
                $this->artifact('endpoint-worker-host-pids.txt'),
                $this->artifact('endpoint-host-identities.json'),
            );
            $this->checkpoint('endpoint-complete');
            $this->operator('recover', 0);
            $this->operator('recover', 0);
            $this->operator('status', 0);
            $this->assertRetired();
            $this->copy($this->work('status.json'), $this->artifact('endpoint-retirement.json'));
            $this->hostIdentities(
                'verify',
                $this->artifact('endpoint-host-identities.json'),
                $this->artifact('endpoint-containment.json'),
            );
            echo "PASS: movie endpoint playback completed and both worker units retired.\n";
            return;
        }
        $checkpoint = $this->capacityCheckpoint();
        if ($checkpoint !== '') {
            $this->commands->run(
                [
                    $checkpoint,
                    $s['artifacts'],
                    $s['controlName'],
                    $s['workerName'],
                    $s['ipcVolume'],
                    $s['runId'] . '-postgres',
                    'worker_operator_test',
                ],
                terminationGrace: 40,
            );
            $this->operator('recover', 0);
            $this->operator('recover', 0);
            $this->operator('status', 0);
            $this->assertRetired();
            $this->copy($this->work('status.json'), $this->artifact('capacity-retirement.json'));
            echo "PASS: capacity checkpoint completed and both immutable worker units retired.\n";
            return;
        }
        $this->operator('status', 0);
        $result = $this->result();
        $this->expect(
            $result['manifestMatches'] === true && $result['retirement'] === 'none' && $result['reservation'] === 'this_boot'
            && $result['readiness'] === 'not_checked'
            && array_all(
                $result['units'],
                static fn(array $unit): bool => $unit['startClaimed'] && is_string($unit['containerId']) && strlen($unit['containerId']) === 64,
            ),
            'Started operator status is inconsistent',
        );
        if ($this->configuration->twoCommand) {
            $this->attachedChecks();
        }
        $this->operator('recover', 0);
        $this->operator('recover', 0);
        $this->operator('readiness', 1);
        $this->operator('status', 0);
        $this->assertRetired(true);
        $this->expect($this->result()['readiness'] === 'not_checked', 'Retired readiness changed');
        $this->operator('create', 3);
        $this->operator('start', 3);
        foreach ([$s['controlName'], $s['workerName']] as $name) {
            $this->expect(
                $this->output([
                    'container',
                    'ls',
                    '--all',
                    '--no-trunc',
                    '--filter',
                    'name=^/' . $name . '$',
                    '--format',
                    '{{.ID}}',
                ]) === '',
                'Retired unit still exists',
            );
        }
        if ($this->configuration->twoCommand) {
            $this->hostIdentities(
                'verify',
                $this->artifact('worker-host-identities.json'),
                $this->artifact('containment.json'),
            );
            $this->snapshot('web', $s['webName'], 'web-worker-contained');
            $this->checkpoint('worker-contained');
            $this->combinedPhase('pause-absent');
            $this->boot(bin2hex(random_bytes(16)), true);
            $this->operator('create', 0);
            $this->operator('start', 0);
            $this->awaitCheck('ready-recovered');
            $this->awaitCheck('ready-recovered', $s['workerName']);
            $this->combinedPhase('recovered');
            $this->snapshot('worker', $s['workerName'], 'worker-recovered');
            $this->snapshot('web', $s['webName'], 'web-worker-recovered');
            $this->checkpoint('worker-recovered');
            $this->finalDrill();
        }
        echo "PASS: trusted operator created and reconciled both stopped immutable units, started real application roles, processed outbox/scheduler effects, and retired the exact deployment without same-boot reuse.\n";
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        if ($arguments !== []) {
            throw new \InvalidArgumentException('This drill does not accept arguments');
        }
        $qualification = new self($root, getenv());
        $status = 1;
        try {
            $qualification->execute();
            $status = 0;
        } catch (Throwable $error) {
            $status = FixtureRuntime::status($error);
            fwrite(STDERR, FixtureRuntime::failure($error) . "\n");
        } finally {
            try {
                $status = new OperatorCleanup($qualification->commands, $qualification->state)->run($status);
            } catch (Throwable $error) {
                fwrite(STDERR, 'Cleanup failed: ' . $error->getMessage() . "\n");
                if ($status === 0) {
                    $status = 1;
                }
            }
            $qualification->commands->close();
        }
        return $status;
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
RUN rm -rf /var/www/html/src /var/www/html/tests /var/www/html/config /var/www/html/packages /var/www/html/migrations /var/www/html/bin /var/www/html/templates /var/www/html/public /var/www/html/vendor
COPY --chown=www-data:www-data checkout/ /var/www/html/
ARG MEDIA_CONTROL_OBSERVATION=0
RUN if [ "$MEDIA_CONTROL_OBSERVATION" = 1 ]; then \
        mkdir -p /usr/lib/baander-fixture && mv /usr/bin/ffmpeg /usr/lib/baander-fixture/ffmpeg \
        && cp /var/www/html/tests/Fixtures/Worker/media-control-ffmpeg.php /usr/bin/ffmpeg \
        && chmod 755 /usr/bin/ffmpeg; \
    fi
RUN rm -rf /var/www/html/var/cache && mkdir -p /var/www/html/var/cache /var/www/html/var/log && chown -R www-data:www-data /var/www/html/var
RUN rm -rf /var/www/html/config/secrets && mkdir -p /var/www/html/config/secrets/oauth \
    && openssl genrsa -out /var/www/html/config/secrets/oauth/private.key 2048 2>/dev/null \
    && openssl rsa -in /var/www/html/config/secrets/oauth/private.key -pubout -out /var/www/html/config/secrets/oauth/public.key 2>/dev/null \
    && chown -R www-data:www-data /var/www/html/config/secrets
ENV APP_ENV=prod APP_DEBUG=0 XDEBUG_MODE=off
WORKDIR /var/www/html
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
mkdir -p /var/www/html/storage/media /var/www/html/storage/conversions /scratch-secrets/oauth
openssl genrsa -out /scratch-secrets/oauth/private.key 2048 2>/dev/null
openssl rsa -in /scratch-secrets/oauth/private.key -pubout -out /scratch-secrets/oauth/public.key 2>/dev/null
chown -R www-data:www-data /media/source /var/www/html/storage /var/www/html/var/transcode_state /scratch-secrets /run/baander-worker
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
