<?php

declare(strict_types=1);

namespace Baander\Tooling\Backend;

use Baander\Tooling\Development\{Cli, Docker, Environment, Runner};
use Baander\Tooling\Process\{ProcessRunner, ProcessResult, ProcessSpec, RunningProcess, SignalScope};
use RuntimeException;
use Throwable;

class Runtime extends Runner
{
    public const string CONTAINER_ROOT = '/var/www/html';
    public const string RUN_DIRECTORY = '/tmp/baander-run';
    public const string SWOOLE_LOADER = '/usr/local/etc/php/conf.d/docker-php-ext-swoole.ini';
    public float $deadline;
    public bool $stopped = false;
    /** @var list<string> */
    public array $containers = [];
    /** @var list<string> */
    public array $networks = [];
    /** @var list<string> */
    public array $volumes = [];
    /** @var list<string> */
    public array $projects = [];
    /** @var array<string,array<string,string>> */
    public array $projectEnvironments = [];
    /** @var list<string> */
    public array $projectContainers = [];
    /** @var list<string> */
    public array $projectNetworks = [];
    /** @var array<string,string> */
    public readonly array $environment;
    public readonly bool $disableSwoole;
    public string $prefix;
    public string $image;
    public ?string $archive = null;
    private readonly ProcessRunner $processes;
    private readonly SignalScope $signals;
    private ?Docker $dockerAdapter = null;
    private bool $independent = false;
    /** @var array{containers:list<string>,networks:list<string>,volumes:list<string>} */
    public array $resources = ['containers' => [], 'networks' => [], 'volumes' => []];
    /** @var list<string> */
    public array $cleanupErrors = [];

    /** @param array<string,string>|null $environment */
    public function __construct(
        public readonly string $artifacts,
        int $timeout,
        public readonly string $root,
        ?array $environment = null,
    ) {
        $this->environment = $environment ?? getenv();
        $disable = $this->environment['BAANDER_TEST_DISABLE_SWOOLE'] ?? '0';
        if (!in_array($disable, ['0', '1'], true)) {
            throw new \InvalidArgumentException('BAANDER_TEST_DISABLE_SWOOLE must be 0 or 1');
        }
        $this->disableSwoole = $disable === '1';
        $this->deadline = self::now() + $timeout;
        $this->prefix = 'baander-tests-' . bin2hex(random_bytes(6));
        $this->image = $this->environment['BAANDER_TEST_IMAGE'] ?? 'martinjuul/baander-app:latest';
        $this->processes = new ProcessRunner();
        $this->signals = new SignalScope(function (): void {
            $this->stopped = true;
        });
    }

    public static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function close(): void
    {
        $this->signals->close();
    }

    /** @param array<string,string>|null $target */
    public function connect(?array $target = null): void
    {
        if ($target !== null) {
            $this->dockerAdapter = new Docker($this, $target);
            return;
        }
        $context = $this->environment['BAANDER_TEST_DOCKER_CONTEXT'] ?? '';
        if ($context === '') {
            $context = $this->environment['DOCKER_CONTEXT'] ?? '';
        }
        $host = $this->environment['DOCKER_HOST'] ?? '';
        if ($context !== '') {
            $target = Cli::dockerTarget($this, $context);
        } else {
            $executable = Environment::executable('docker') ?? throw new RuntimeException('Install Docker with Compose first');
            if ($host !== '') {
                $target = ['executable' => $executable, 'host' => $host];
                $docker = new Docker($this, $target);
                $information = json_decode($docker->run(['info', '--format', '{{json .}}'])->stdout, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($information) || !is_string($information['ID'] ?? null) || $information['ID'] === '') {
                    throw new RuntimeException('Cannot verify Docker daemon identity');
                }
                $target['daemon_id'] = $information['ID'];
                $docker->run(['compose', 'version']);
            } else {
                $context = trim($this->run([$executable, 'context', 'show'])->stdout);
                if ($context === '') {
                    throw new RuntimeException('Cannot determine current Docker context');
                }
                $target = Cli::dockerTarget($this, $context);
            }
        }
        $this->dockerAdapter = new Docker($this, $target);
    }

    /** @return non-empty-list<string> */
    public function dockerArguments(): array
    {
        return ($this->dockerAdapter ?? throw new RuntimeException('Docker target is not connected'))->commandPrefix();
    }

    /** @param non-empty-list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function run(
        array $arguments,
        ?string $cwd = null,
        ?array $env = null,
        ?string $input = null,
        ?float $timeout = 120,
        bool $check = true,
        ?string $logPath = null,
        bool $interactive = false,
    ): ProcessResult {
        $process = $this->startCommand($arguments, $cwd, $env, $input, $timeout, $logPath, $interactive);
        return $this->waitCommand($process, $arguments, $check, $logPath);
    }

    /** @param non-empty-list<string> $arguments
     * @param array<string,string>|null $env
     */
    private function startCommand(
        array $arguments,
        ?string $cwd = null,
        ?array $env = null,
        ?string $input = null,
        ?float $timeout = null,
        ?string $logPath = null,
        bool $interactive = false,
    ): RunningProcess {
        if (!$this->independent) {
            $this->signals->dispatch();
            if ($this->stopped) {
                throw new RuntimeException('Backend run interrupted');
            }
            $remaining = $this->deadline - self::now();
            if ($remaining <= 0) {
                throw new RuntimeException('Backend overall timeout expired');
            }
            $timeout = $timeout === null ? $remaining : min($timeout, $remaining);
        }
        return $this->processes->start(new ProcessSpec(
            argv: $arguments,
            cwd: $cwd ?? $this->root,
            environment: $env ?? $this->environment,
            timeout: $timeout,
            stdin: $input,
            logPath: $logPath,
            passthrough: $interactive,
            processGroup: !$interactive,
            ignoreCancellation: $this->independent,
            appendLog: true,
        ));
    }

    /** @param non-empty-list<string> $arguments */
    private function waitCommand(RunningProcess $process, array $arguments, bool $check, ?string $log): ProcessResult
    {
        $result = $this->processes->wait($process);
        if ($result->cancelled || $result->timedOut) {
            if (!$this->independent) {
                $this->stopped = true;
            }
            throw new RuntimeException('Backend run interrupted or command timeout expired: ' . $arguments[0]);
        }
        if ($check && $result->exitCode !== 0) {
            throw new RuntimeException('Command failed (' . $result->exitCode . '): ' . implode(' ', array_slice($arguments, 0, 3))
                . "\n" . ($log ?? substr($result->stdout . $result->stderr, -4000)));
        }
        return $result;
    }

    /** @param list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function docker(array $arguments, ?string $log = null, bool $check = true, ?array $env = null, ?float $timeout = null): ProcessResult
    {
        return ($this->dockerAdapter ?? throw new RuntimeException('Docker target is not connected'))->run(
            $arguments,
            env: $env,
            timeout: $timeout,
            check: $check,
            logPath: $log,
        );
    }

    /** @param list<string> $arguments
     * @return list<string>
     */
    public function composeArguments(string $project, array $arguments): array
    {
        return [
            'compose',
            '--project-name', $project,
            '--project-directory', $this->root,
            '--file', $this->root . '/compose.test.yaml',
            ...$arguments,
        ];
    }

    /** @param list<string> $arguments */
    public function compose(string $project, array $arguments, ?string $log = null, bool $check = true): ProcessResult
    {
        if (!in_array($project, $this->projects, true)) {
            throw new RuntimeException('Unregistered Compose test project: ' . $project);
        }
        return $this->docker($this->composeArguments($project, $arguments), $log, $check, $this->projectEnvironments[$project]);
    }

    public function serviceContainer(string $project, string $service): string
    {
        $identifiers = preg_split('/\s+/', trim($this->compose($project, ['ps', '--quiet', $service])->stdout));
        if (count($identifiers) !== 1 || preg_match('/^[a-f0-9]{12,64}$/D', $identifiers[0]) !== 1) {
            throw new RuntimeException('Expected one running ' . $service . ' container for Compose project ' . $project);
        }
        $identifier = $identifiers[0];
        if (!in_array($identifier, $this->projectContainers, true)) {
            $this->containers[] = $identifier;
            $this->projectContainers[] = $identifier;
        }
        return $identifier;
    }

    public function prepareArchive(): void
    {
        if (($this->environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] ?? '') === '1') {
            return;
        }
        [, , $this->archive] = StaticCheck::prepareCheckout($this, $this->root, $this->artifacts);
    }

    public function createApp(
        string $lane,
        string $network = 'none',
        bool $privileged = false,
        bool $services = false,
        bool $coverage = false,
    ): string {
        if ($services) {
            if (!in_array($network, $this->projects, true)) {
                throw new RuntimeException('Service app requires a registered Compose project');
            }
            $this->projectEnvironments[$network]['BAANDER_TEST_XDEBUG_MODE'] = $coverage ? 'coverage' : 'off';
            $this->projectEnvironments[$network]['BAANDER_TEST_APP_PRIVILEGED'] = $privileged ? 'true' : 'false';
            $this->compose($network, ['up', '--detach', '--wait', '--wait-timeout', '60', 'app']);
            $name = $this->serviceContainer($network, 'app');
        } else {
            $name = $this->prefix . '-' . $lane;
            $this->containers[] = $name;
            $arguments = [
                'create',
                '--user', '0:0',
                '--name', $name,
                '--network', $network,
                '--label', 'com.baander.backend.run=' . $this->prefix,
                '--workdir', self::CONTAINER_ROOT,
                '--entrypoint', 'sh',
                '-e', 'APP_ENV=test',
                '-e', 'APP_DEBUG=0',
                '-e', 'XDEBUG_MODE=' . ($coverage ? 'coverage' : 'off'),
            ];
            if ($privileged) {
                $arguments[] = '--privileged';
            }
            $this->docker([...$arguments, $this->image, '-c', 'exec sleep infinity']);
            $this->docker(['start', $name]);
        }
        if ($this->archive !== null) {
            $this->docker(['cp', $this->archive, $name . ':/tmp/checkout.tar']);
            $this->docker(['exec', $name, 'sh', '-ec', <<<'SH'
                rm -rf /var/www/html
                mkdir -p /var/www/html
                tar -xf /tmp/checkout.tar -C /var/www/html
                rm /tmp/checkout.tar
                chown -R www-data:www-data /var/www/html
                SH]);
        }
        $this->docker(['exec', $name, 'sh', '-ec', 'mkdir -p ' . self::RUN_DIRECTORY . '; chown www-data:www-data ' . self::RUN_DIRECTORY]);
        if ($this->disableSwoole) {
            $this->qualifyWithoutSwoole($name, $lane);
        }
        return $name;
    }

    public function startServices(string $lane): string
    {
        $project = $this->prefix . '-' . $lane;
        if (in_array($project, $this->projects, true)) {
            throw new RuntimeException('Compose test project already registered: ' . $project);
        }
        $this->projects[] = $project;
        $this->projectEnvironments[$project] = array_replace($this->environment, [
            'BAANDER_TEST_IMAGE' => $this->image,
            'BAANDER_TEST_POSTGRES_IMAGE' => $this->environment['BAANDER_TEST_POSTGRES_IMAGE'] ?? 'baander-database:latest',
            'BAANDER_TEST_REDIS_IMAGE' => $this->environment['BAANDER_TEST_REDIS_IMAGE'] ?? 'redis/redis-stack-server:edge',
            'BAANDER_TEST_XDEBUG_MODE' => 'off', 'BAANDER_TEST_APP_PRIVILEGED' => 'true']);
        $this->networks[] = $project . '_private';
        $this->projectNetworks[] = $project . '_private';
        array_push($this->volumes, $project . '_postgres_data', $project . '_redis_data');
        $this->compose($project, ['up', '--detach', '--wait', '--wait-timeout', '60', 'postgres', 'redis']);
        $this->serviceContainer($project, 'postgres');
        $this->serviceContainer($project, 'redis');
        return $project;
    }

    /** @param list<string> $arguments */
    public function helper(string $app, array $arguments, ?string $log = null, bool $check = true): ProcessResult
    {
        $this->docker(['exec', $app, 'chown', '-R', 'www-data:www-data', self::RUN_DIRECTORY]);
        return $this->docker(['exec', '--user', 'www-data', $app, 'php', '-d', 'memory_limit=2G',
            'scripts/backend-test-runner.php', ...$arguments], $log, $check);
    }

    /** @param array{files:list<string>,processes:int,batch:int,services:bool,privileged:bool} $spec
     * @return array{app:string,lane:string,run:string,started:float,process:RunningProcess}
     */
    public function startLane(string $lane, array $spec, string $run, bool $coverage): array
    {
        $started = self::now();
        $network = $spec['services'] ? $this->startServices($lane) : 'none';
        $app = $this->createApp($lane, $network, $spec['privileged'], $spec['services'], $coverage);
        $log = $this->artifacts . '/' . $lane . '.log';
        try {
            $this->docker(['cp', $run . '/.', $app . ':' . self::RUN_DIRECTORY]);
            if ($spec['services']) {
                $this->docker(['exec', '--user', 'www-data', $app, 'sh', '-ec', <<<'SH'
                    mkdir -p /tmp/baander-oauth
                    umask 077
                    openssl genrsa -out "$OAUTH_PRIVATE_KEY_PATH" 2048
                    openssl rsa -in "$OAUTH_PRIVATE_KEY_PATH" -pubout -out "$OAUTH_PUBLIC_KEY_PATH"
                    php -d memory_limit=512M bin/console cache:clear --env=test --no-debug
                    php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=test
                    php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=test
                    SH], $log);
            }
            $this->docker(['exec', $app, 'chown', '-R', 'www-data:www-data', self::RUN_DIRECTORY]);
            $arguments = [...$this->dockerArguments(), 'exec', '--user', 'www-data', $app, 'php', '-d', 'memory_limit=2G',
                'scripts/backend-test-runner.php', 'run', self::RUN_DIRECTORY, $lane];
            $process = $this->startCommand($arguments, logPath: $log);
            return compact('app', 'lane', 'run', 'started', 'process');
        } catch (Throwable $error) {
            $this->collectLane($app, $lane, $run);
            throw $error;
        }
    }

    /** @param array{app:string,lane:string,run:string,started:float,process:RunningProcess} $lane */
    public function finishLane(array $lane): int
    {
        try {
            $result = $this->waitCommand($lane['process'], ['docker', 'exec'], false, $this->artifacts . '/' . $lane['lane'] . '.log');
            printf(
                "%s: exit %d, %.1fs; %s/%s.log\n",
                $lane['lane'],
                $result->exitCode,
                self::now() - $lane['started'],
                $this->artifacts,
                $lane['lane'],
            );
            return $result->exitCode;
        } finally {
            $this->collectLane($lane['app'], $lane['lane'], $lane['run']);
        }
    }

    public function collectLane(string $app, string $lane, string $run): void
    {
        $destination = $this->artifacts . '/' . $lane;
        if (!is_dir($destination)) {
            mkdir($destination, 0700, true);
        }
        try {
            $this->independently(fn() => $this->docker(['cp', $app . ':' . self::RUN_DIRECTORY . '/.', $destination], check: true, timeout: 30));
            foreach (glob($destination . '/' . $lane . '.*') ?: [] as $path) {
                if (is_file($path) && !copy($path, $run . '/' . basename($path))) {
                    throw new RuntimeException('Cannot retain lane report: ' . $path);
                }
            }
        } catch (Throwable $error) {
            fwrite(STDERR, 'Artifact collection failed for ' . $lane . ': ' . $error->getMessage() . "\n");
        }
    }

    /** @template T
     * @param callable():T $operation
     * @return T
     */
    private function independently(callable $operation): mixed
    {
        $previous = $this->independent;
        $this->independent = true;
        try {
            return $operation();
        } finally {
            $this->independent = $previous;
        }
    }

    /** @return array{containers:list<string>,networks:list<string>,volumes:list<string>} */
    public function inventory(string $phase): array
    {
        return $this->independently(function () use ($phase): array {
            $resources = ['containers' => [], 'networks' => [], 'volumes' => []];
            $standalone = $this->inventoryCommand(
                ['ps', '-a', '-q', '--filter', 'label=com.baander.backend.run=' . $this->prefix],
                $this->artifacts . '/' . $phase . '-standalone-containers.log',
            );
            $resources['containers'] = trim($standalone->stdout) === '' ? [] : preg_split('/\s+/', trim($standalone->stdout));
            foreach ($this->projects as $project) {
                $listings = [
                    'containers' => ['ps', '-a', '-q'],
                    'networks' => ['network', 'ls', '-q'],
                    'volumes' => ['volume', 'ls', '-q'],
                ];
                foreach ($listings as $kind => $listing) {
                    $arguments = [...$listing, '--filter', 'label=com.docker.compose.project=' . $project];
                    $log = $this->artifacts . '/' . $phase . '-' . $project . '-' . $kind . '.log';
                    $result = $this->inventoryCommand($arguments, $log);
                    array_push($resources[$kind], ...array_filter(preg_split('/\s+/', trim($result->stdout)) ?: []));
                }
            }
            if ($resources['containers'] !== []) {
                $result = $this->inventoryCommand(
                    ['inspect', ...array_unique($resources['containers'])],
                    $this->artifacts . '/' . $phase . '-container-inspect.log',
                );
                foreach (json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR) as $container) {
                    foreach ($container['Mounts'] as $mount) {
                        if ($mount['Type'] === 'volume') {
                            $resources['volumes'][] = $mount['Name'];
                        }
                    }
                }
            }
            foreach ($resources as &$values) {
                $values = $values |> array_unique(...) |> array_values(...);
                sort($values, SORT_STRING);
            }
            return $resources;
        });
    }

    public function cleanup(): bool
    {
        return $this->independently(function (): bool {
            try {
                $this->resources = $this->inventory('before-cleanup');
                file_put_contents(
                    $this->artifacts . '/resources.json',
                    json_encode(
                        ['projects' => $this->projects, ...$this->resources],
                        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                    ) . "\n",
                );
            } catch (Throwable $error) {
                $this->cleanupErrors[] = $error->getMessage();
            }
            foreach (array_reverse($this->projects) as $project) {
                $this->cleanupCommand(
                    $this->composeArguments($project, ['logs', '--no-color']),
                    10,
                    $this->artifacts . '/' . $project . '.compose.log',
                    $this->projectEnvironments[$project],
                    false,
                );
                $this->cleanupCommand(
                    $this->composeArguments($project, ['down', '--volumes', '--remove-orphans', '--timeout', '5']),
                    30,
                    env: $this->projectEnvironments[$project],
                );
            }
            foreach (array_reverse($this->containers) as $name) {
                if (in_array($name, $this->projectContainers, true)) {
                    continue;
                }
                $this->cleanupCommand(['logs', $name], 10, $this->artifacts . '/' . $name . '.container.log', removal: false);
                $this->cleanupCommand(['rm', '-f', '--volumes', $name], 20);
            }
            foreach (array_reverse($this->networks) as $name) {
                if (!in_array($name, $this->projectNetworks, true)) {
                    $this->cleanupCommand(['network', 'rm', $name], 20);
                }
            }
            try {
                $remaining = $this->inventory('after-cleanup');
                foreach ($remaining as $kind => $values) {
                    if ($values !== []) {
                        $this->cleanupErrors[] = 'Compose ' . $kind . ' remain after cleanup: ' . implode(', ', $values);
                    }
                }
            } catch (Throwable $error) {
                $this->cleanupErrors[] = $error->getMessage();
            }
            try {
                $all = $this->inventoryCommand(['volume', 'ls', '-q'], $this->artifacts . '/after-cleanup-all-volumes.log');
                $surviving = array_intersect(preg_split('/\s+/', trim($all->stdout)) ?: [], $this->resources['volumes']);
                if ($surviving !== []) {
                    $this->cleanupErrors[] = 'Recorded volumes remain after cleanup: ' . implode(', ', $surviving);
                }
            } catch (Throwable $error) {
                $this->cleanupErrors[] = $error->getMessage();
            }
            if ($this->archive !== null && is_file($this->archive)) {
                unlink($this->archive);
            }
            if (is_dir($this->artifacts . '/source')) {
                $this->run(['rm', '-rf', '--', $this->artifacts . '/source'], timeout: 30, check: false);
            }
            foreach ($this->cleanupErrors as $error) {
                fwrite(STDERR, 'Cleanup failed: ' . $error . "\n");
            }
            return $this->cleanupErrors === [];
        });
    }

    /** @param list<string> $arguments
     * @param array<string,string>|null $env
     */
    private function cleanupCommand(array $arguments, float $timeout, ?string $log = null, ?array $env = null, bool $removal = true): void
    {
        try {
            $this->docker($arguments, $log, true, $env, $timeout);
        } catch (Throwable $error) {
            if ($removal) {
                $this->cleanupErrors[] = $error->getMessage();
            } else {
                fwrite(STDERR, 'Log collection failed: ' . $error->getMessage() . "\n");
            }
        }
    }

    /** @param list<string> $arguments */
    private function inventoryCommand(array $arguments, string $log): ProcessResult
    {
        try {
            $process = $this->startCommand([...$this->dockerArguments(), ...$arguments], timeout: 15);
            $result = $this->processes->wait($process);
            file_put_contents($log, json_encode($arguments, JSON_THROW_ON_ERROR) . "\n" . $result->stdout . $result->stderr);
        } catch (Throwable $error) {
            file_put_contents($log, json_encode($arguments, JSON_THROW_ON_ERROR) . "\n" . $error->getMessage() . "\n");
            throw $error;
        }
        if ($result->exitCode !== 0 || $result->timedOut || $result->cancelled) {
            throw new RuntimeException('Could not inventory Docker resources; see ' . $log);
        }
        return $result;
    }

    /** @param array<string,mixed> $before
     * @param array<string,mixed>|null $after
     */
    public static function validateSwooleRuntime(array $before, ?array $after = null): void
    {
        if (($before['sapi'] ?? null) !== 'cli' || ($before['php_ini'] ?? null) !== '/usr/local/etc/php/php.ini'
            || !isset($before['configuration'][$before['php_ini']], $before['configuration'][self::SWOOLE_LOADER])
            || ($before['loader_symlink'] ?? true) || !in_array('swoole', $before['extensions'] ?? [], true)) {
            throw new RuntimeException('Unexpected PHP runtime or missing dedicated Swoole loader');
        }
        $directives = explode("\n", $before['loader'] ?? '')
            |> (static fn(array $lines): array => array_map(trim(...), $lines))
            |> (static fn(array $lines): array => array_filter($lines, static fn(string $line): bool => $line !== '' && !str_starts_with($line, ';')))
            |> array_values(...);
        if (count($directives) !== 1 || preg_match('/^extension\s*=\s*swoole(?:\.so)?$/D', $directives[0]) !== 1) {
            throw new RuntimeException('Unexpected dedicated Swoole loader contents');
        }
        if ($after === null) {
            return;
        }
        $configuration = $before['configuration'];
        unset($configuration[self::SWOOLE_LOADER]);
        $extensions = $before['extensions']
            |> (static fn(array $extensions): array => array_filter($extensions, static fn(string $name): bool => $name !== 'swoole'))
            |> array_values(...);
        sort($extensions, SORT_STRING);
        $actual = $after['configuration'] ?? [];
        ksort($configuration);
        ksort($actual);
        foreach (['php_version', 'binary', 'sapi', 'php_ini', 'xdebug_mode', 'xdebug_environment'] as $key) {
            if (($after[$key] ?? null) !== ($before[$key] ?? null)) {
                throw new RuntimeException('Swoole removal changed PHP runtime setting: ' . $key);
            }
        }
        if ($actual !== $configuration || ($after['extensions'] ?? []) !== $extensions
            || ($after['loader'] ?? null) !== null || ($after['loader_symlink'] ?? true)
            || ($after['native_symbols'] ?? ['missing']) !== [] || ($after['autoload'] ?? false) !== true) {
            throw new RuntimeException('Swoole removal changed an unexpected PHP runtime setting or autoload failed');
        }
    }

    public function qualifyWithoutSwoole(string $app, string $lane): void
    {
        $log = $this->artifacts . '/' . $lane . '.runtime.log';
        try {
            $before = $this->runtimeEvidence($app, $lane, 'before');
            self::validateSwooleRuntime($before);
            $this->docker(['exec', $app, 'rm', '--', self::SWOOLE_LOADER], $log);
            $after = $this->runtimeEvidence($app, $lane, 'after');
            self::validateSwooleRuntime($before, $after);
            file_put_contents($log, "Qualified PHP without Swoole; other extensions and configuration preserved.\n", FILE_APPEND);
        } catch (Throwable $error) {
            file_put_contents($log, $error->getMessage() . "\n", FILE_APPEND);
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function runtimeEvidence(string $app, string $lane, string $phase): array
    {
        $path = $this->artifacts . '/' . $lane . '.runtime-' . $phase . '.json';
        $this->docker(['exec', '--user', 'www-data', $app, 'php', '-r', self::RUNTIME_PROBE, $phase], $path);
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private const string RUNTIME_PROBE = <<<'PHP_PROBE'
$loader = '/usr/local/etc/php/conf.d/docker-php-ext-swoole.ini';
$scanned = array_values(array_filter(array_map('trim', explode(',', php_ini_scanned_files() ?: ''))));
$configuration = [];
foreach (array_merge([php_ini_loaded_file()], $scanned) as $file) {
    if ($file !== false) {
        $configuration[$file] = hash_file('sha256', $file);
    }
}
$extensions = get_loaded_extensions();
sort($extensions);
$symbols = array_merge(
    get_declared_classes(),
    get_declared_interfaces(),
    get_declared_traits(),
    get_defined_functions()['internal'],
    array_keys(get_defined_constants()),
);
$symbols = array_values(array_filter(
    $symbols,
    static fn (string $name): bool => preg_match('/^(?:swoole(?:[\\\\_]|$)|co\\\\)/i', $name) === 1,
));
$evidence = [
    'php_version' => PHP_VERSION,
    'binary' => PHP_BINARY,
    'sapi' => PHP_SAPI,
    'php_ini' => php_ini_loaded_file(),
    'configuration' => $configuration,
    'xdebug_mode' => ini_get('xdebug.mode'),
    'xdebug_environment' => getenv('XDEBUG_MODE'),
    'extensions' => $extensions,
    'native_symbols' => $symbols,
    'loader' => is_file($loader) ? file_get_contents($loader) : null,
    'loader_symlink' => is_link($loader),
    'autoload' => false,
];
try {
    if ($argv[1] === 'after') {
        require '/var/www/html/vendor/autoload.php';
        $evidence['autoload'] = true;
    }
} catch (Throwable $error) {
    $evidence['error'] = $error->getMessage();
}
echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
exit(isset($evidence['error']) ? 1 : 0);
PHP_PROBE;
}
