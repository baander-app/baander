<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Process\{CommandExecutor, ProcessSpec, ProcessResult, CompletionPolicy};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;
use Baander\Tooling\Resources\{ResourceKind, ResourceLedger};

class Workers
{
    private readonly Connections $connections;

    public function __construct(private readonly StateStore $store, private readonly CommandExecutor $runner, ?Connections $connections = null)
    {
        $this->connections = $connections ?? new Connections($runner, Environment::clean());
    }

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     */
    public function prepare(array &$instance, array $release): void
    {
        $home = \Baander\Tooling\Configuration\ApplicationLayout::fromArray($release['layout'])->root;
        $cleanupSeconds = $release['effective']['budgets']['cleanup'] ?? 120.0;
        $previous = $instance['workers'] ?? null;
        if ($previous && ($previous['phase'] ?? null) !== 'retired') {
            throw new WorkflowError('Retire the saved worker deployment before creating a fresh boot.');
        }
        $docker = $this->connections->client($instance['docker']);
        $information = $docker->validateIdentity();
        if (($information['NCPU'] ?? 0) < 2 || ($information['MemTotal'] ?? 0) < 8448 * 1024 ** 2) {
            throw new WorkflowError('A worker pair requires at least two CPUs and 8448 MiB of daemon RAM.');
        }
        $endpoint = $docker->target->endpoint;
        if (!str_starts_with($endpoint, 'unix://')) {
            throw new WorkflowError('The local worker controller requires a daemon-visible Unix socket.');
        }
        $boot = bin2hex(random_bytes(16));
        $directory = $this->store->directory($instance['id']) . '/workers/' . $boot;
        PrivateDirectory::prepare($directory);
        $profile = (\Baander\Tooling\Resources\DisposableContainer::run($docker, $directory . '/profile-resources.json', [
            '--network',
            'none',
            '--entrypoint',
            'php',
            $release['images']['worker_image']['resolvedId'],
            '-r',
            'require "vendor/autoload.php"; echo (new \\App\\Shared\\Application\\DTO\\WorkerAutoscalingProfile())->toJson();',
        ], $this->connections->environment(), $cleanupSeconds)->stdout
            |> (static fn(string $output): mixed => json_decode($output, true, flags: JSON_THROW_ON_ERROR)));
        $mounts = [];
        $sharedVolumes = [];
        foreach ($instance['media'] ?? [] as $item) {
            $mounts[] = [
                'source' => \Baander\Tooling\Filesystem\Paths::resolvedPath($item['source']),
                'target' => $item['target'],
                'role' => 'source',
                'readOnly' => true,
            ];
        }
        $runtime = new Runtime($this->store, $this->runner, $this->connections);
        $environment = $runtime->environment($instance);
        $configuration = $runtime->config($instance, $release);
        foreach ([
            ['media', $environment['MEDIA_STORAGE_PATH'] ?? '/storage/media', 'source'],
            ['storage', $home . '/storage', 'derived'],
            ['conversions', $environment['CONVERT_STORAGE_PATH'] ?? '/storage/conversions', 'derived'],
            ['transcode_state', $home . '/var/transcode_state', 'derived'],
        ] as [$key, $defaultTarget, $role]) {
            $target = $instance['state_mounts'][$key]['target'] ?? $defaultTarget;
            if ($role === 'source' && array_any(
                $mounts,
                static fn(array $mount): bool => $mount['target'] === $target,
            )) {
                continue;
            }
            $configured = array_find(
                $configuration['services']['app']['volumes'],
                static fn(array $mount): bool => $mount['target'] === $target,
            );
            if ($configured && $role === 'source' && $configured['type'] === 'bind' && !empty($configured['read_only'])) {
                $mounts[] = [
                    'source' => \Baander\Tooling\Filesystem\Paths::resolvedPath($configured['source']),
                    'target' => $target,
                    'role' => 'source',
                    'readOnly' => true,
                ];
                continue;
            }
            if ($configured === null || $configured['type'] !== 'volume') {
                throw new WorkflowError('Worker shared ' . $key . ' requires a named volume; relocate adopted bind storage before enabling workers.');
            }
            $name = $configuration['volumes'][$configured['source']]['name'];
            $sharedVolumes[] = $name;
            $mounts[] = [
                'volume' => $name,
                'target' => $target,
                'role' => $role,
                'readOnly' => $role === 'source',
            ];
        }
        $ipc = $instance['project'] . '-ipc-' . $boot;
        $manifest = [
            'version' => 5,
            'sourceMountPolicy' => 'local-development',
            'namespace' => $instance['project'],
            'bootId' => $boot,
            'daemonId' => $docker->target->daemonId,
            'imageId' => $release['images']['worker_image']['resolvedId'],
            'network' => Runtime::networkName($instance),
            'dockerBinary' => '/usr/local/bin/docker',
            'dockerEndpoint' => 'unix:///var/run/docker.sock',
            'controlMemoryMiB' => 2944,
            'executionMemoryMiB' => 4480,
            'controlNanoCpus' => 1000000000,
            'executionNanoCpus' => 1000000000,
            'controlCpuSet' => [0],
            'executionCpuSet' => [1],
            'controlPidsLimit' => 129,
            'executionPidsLimit' => 256,
            'autoscaling' => $profile,
            'scheduledConsoleMiB' => 320,
            'ipcVolume' => $ipc,
            'mediaMounts' => $mounts,
        ];
        $allowed = [
            'APP_SECRET',
            'DATABASE_URL',
            'REDIS_URL',
            'REDIS_PASSWORD',
            'MESSENGER_TRANSPORT_DSN',
            'MAILER_DSN',
            'CONVERT_STORAGE_PATH',
            'MEDIA_STORAGE_PATH',
            'MAIL_FROM_ADDRESS',
            'MAIL_FROM_NAME',
            'VAPID_PRIVATE_KEY',
            'VAPID_PUBLIC_KEY',
            'STREAM_URL_HMAC_SECRET',
        ];
        $credentials = [
            'controllerDatabaseUrl' => $environment['DATABASE_URL'],
            'runtimeEnvironment' => array_replace(
                array_intersect_key($environment, array_flip($allowed)),
                [
                    'APP_ENV' => 'prod',
                    'APP_DEBUG' => '0',
                    'APP_URL' => 'https://' . $instance['hostname'],
                    'DEFAULT_URI' => 'https://' . $instance['hostname'],
                ],
            ),
        ];
        foreach ([$manifest, $credentials] as $document) {
            if (strlen(json_encode(
                $document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n") > 8192) {
                throw new WorkflowError('Worker operator input exceeds its bounded file size.');
            }
        }
        $validation = [
            '-i',
            '--network',
            'none',
            '--user',
            'www-data',
            '--cap-drop',
            'ALL',
            '--security-opt',
            'no-new-privileges',
            '--entrypoint',
            'php',
            ...self::sourceMountArguments($manifest),
        ];
        $validator = <<<'CODE'
try {
require $argv[1] . '/vendor/autoload.php';
$input = stream_get_contents(STDIN, 32769);
if ($input === false || strlen($input) > 32768) { exit(1); }
$document = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
$manifest = \App\Shared\Infrastructure\Worker\DeploymentOperatorManifest::fromJson(json_encode($document['manifest'], JSON_THROW_ON_ERROR), $argv[1]);
$environment = new \App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment($document['environment']);
foreach (\App\Shared\Application\DTO\DeploymentUnit::cases() as $unit) { $manifest->recipe($unit, $environment); }
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
}
CODE;
        $payload = json_encode(
            ['manifest' => $manifest, 'environment' => $credentials['runtimeEnvironment']],
            JSON_THROW_ON_ERROR,
        );
        if (strlen($payload) > 32768) {
            throw new WorkflowError('Worker operator input exceeds its bounded transfer size.');
        }
        $result = \Baander\Tooling\Resources\DisposableContainer::run($docker, $directory . '/validation-resources.json', [...$validation, $release['images']['operator_image']['resolvedId'], '-r', $validator, $home], $this->connections->environment(), $cleanupSeconds, input: $payload, check: false);
        if ($result->exitCode !== 0) {
            $log = $directory . '/validation.log';
            $recorded = $this->writeDiagnostic($log, $result, json_encode($credentials, JSON_THROW_ON_ERROR));
            throw new WorkflowError('Worker validation failed' . ($recorded ? '; inspect ' . $log . '.' : '.'));
        }
        AtomicFile::writeJson($directory . '/manifest.json', $manifest);
        AtomicFile::writeJson($directory . '/credentials.json', $credentials);
        $instance['workers'] = [
            'directory' => $directory,
            'operator_image' => $release['images']['operator_image']['resolvedId'],
            'layout' => $release['layout'],
            'socket' => substr($endpoint, strlen('unix://')),
            'phase' => 'preparing',
            'boot_id' => $boot,
            'ipc_volume' => $ipc,
            'cleanup_budget' => $cleanupSeconds,
            'preparation' => [
                'version' => 1,
                'manifest' => hash_file('sha256', $directory . '/manifest.json'),
                'credentials' => hash_file('sha256', $directory . '/credentials.json'),
                'worker_image' => $release['images']['worker_image']['resolvedId'],
            ],
        ];
        $this->store->save($instance['id'], $instance);
        foreach ($sharedVolumes as $name) {
            $docker->run(['volume', 'create', '--label', 'app.baander.development.owner=' . $instance['id'], $name]);
        }
        new ResourceLedger($directory . '/ipc-resources.json', $docker, $this->connections->environment())->create(
            ResourceKind::Volume,
            $ipc,
            ['--label', 'app.baander.development.owner=' . $instance['id']],
        );
        $initializeIpc = <<<'CODE'
$directory = '/run/baander-worker';
$user = posix_getpwnam('www-data');
if ($user === false || !chmod($directory, 0700)
    || !chgrp($directory, $user['gid']) || !chown($directory, $user['uid'])) { exit(1); }
CODE;
        \Baander\Tooling\Resources\DisposableContainer::run($docker, $directory . '/ipc-initialize-resources.json', [
            '--network',
            'none',
            '--read-only',
            '--user',
            '0',
            '--cap-drop',
            'ALL',
            '--cap-add',
            'CHOWN',
            '--security-opt',
            'no-new-privileges',
            '--mount',
            'type=volume,src=' . $ipc . ',dst=/run/baander-worker,volume-nocopy',
            '--entrypoint',
            'php',
            $release['images']['worker_image']['resolvedId'],
            '-r',
            $initializeIpc,
        ], $this->connections->environment(), $cleanupSeconds);
        $docker->run([
            'image',
            'tag',
            $release['images']['operator_image']['resolvedId'],
            'baander-development-controller:' . $instance['project'] . '-' . $boot,
        ]);
        $this->phase($instance, 'prepared');
    }

    /** @param array<string,mixed> $manifest
     * @return list<string>
     */
    public static function sourceMountArguments(array $manifest): array
    {
        $arguments = [];
        foreach ($manifest['mediaMounts'] as $media) {
            if ($media['role'] !== 'source' || !isset($media['source'])) {
                continue;
            }
            $source = $media['source'];
            if (!is_dir($source) || realpath($source) !== $source) {
                throw new WorkflowError('The recorded source directory changed; reconcile the saved worker deployment.');
            }
            if (str_contains($source, ',')) {
                throw new WorkflowError('Docker mount paths cannot contain commas.');
            }
            array_push(
                $arguments,
                '--mount',
                'type=bind,src=' . $source . ',dst=' . $source . ',readonly,bind-propagation=rprivate,bind-recursive=readonly',
            );
        }
        return $arguments;
    }

    /** Empty helper-only directories preserve historical retirement checks without exposing source data.
     * @param array<string,mixed> $manifest
     * @return list<string>
     */
    public static function retirementMountArguments(array $manifest, string $applicationRoot): array
    {
        $sources = [];
        foreach ($manifest['mediaMounts'] ?? [] as $mount) {
            if (!isset($mount['source'])) {
                continue;
            }
            $source = $mount['source'];
            if (($mount['role'] ?? null) !== 'source' || ($mount['readOnly'] ?? null) !== true
                || !is_string($source) || strlen($source) > 4096
                || preg_match('~\A/(?:[A-Za-z0-9_. -]+/)*[A-Za-z0-9_. -]+\z~D', $source) !== 1
                || \Baander\Tooling\Filesystem\Paths::absolutePath($source) !== $source) {
                throw new WorkflowError('Retirement source declarations require canonical saved read-only directory paths.');
            }
            foreach ([$applicationRoot, '/operator', '/var/run/docker.sock', '/run/docker.sock', '/etc', '/proc', '/sys', '/dev', '/bin', '/sbin', '/usr', '/lib', '/lib64'] as $reserved) {
                if ($source === $reserved || str_starts_with($source, $reserved . '/') || str_starts_with($reserved, $source . '/')) {
                    throw new WorkflowError('Retirement source placeholder overlaps helper runtime state: ' . $source);
                }
            }
            $sources[$source] = true;
        }
        $paths = array_keys($sources);
        sort($paths, SORT_STRING);
        $arguments = [];
        foreach ($paths as $source) {
            if (array_any($paths, static fn(string $other): bool => str_starts_with($other, $source . '/'))) {
                continue;
            }
            array_push($arguments, '--tmpfs', $source . ':ro,noexec,nosuid,nodev,size=1m,mode=0555');
        }
        return $arguments;
    }

    /** @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    public function invoke(array $instance, string $action): array
    {
        $record = $instance['workers'];
        if ($action === 'create' && isset($record['preparation'])) {
            $saved = $this->store->load($instance['id'])['workers'] ?? [];
            if (($saved['phase'] ?? null) !== 'create_unconfirmed' || ($saved['boot_id'] ?? null) !== $record['boot_id']) {
                throw new WorkflowError('Worker admission requires durable creation intent for this boot.');
            }
        }
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        // The trusted controller needs read access to retained Composer trees owned by www-data.
        // Preserve the daemon socket's existing SELinux label.
        $arguments = [
            '-i',
            '--network',
            Runtime::networkName($instance),
            '--user',
            '0',
            '--cap-drop',
            'ALL',
            '--cap-add',
            'DAC_READ_SEARCH',
            '--security-opt',
            'no-new-privileges',
            '--security-opt',
            'label=disable',
            '--entrypoint',
            '/bin/sh',
            '--mount',
            'type=bind,src=' . $record['socket'] . ',dst=/var/run/docker.sock',
            '--tmpfs',
            '/operator:rw,noexec,nosuid,size=1m,mode=0700',
            '--tmpfs',
            '/tmp:rw,noexec,nosuid,nodev,size=16m,mode=0700',
        ];
        $documents = [];
        foreach (['manifest', 'credentials'] as $name) {
            $contents = @file_get_contents($record['directory'] . '/' . $name . '.json', length: 8193);
            if ($contents === false) {
                throw new WorkflowError('Worker operator inputs are missing or unreadable; preserve the saved ownership record.');
            }
            if (strlen($contents) > 8192) {
                throw new WorkflowError('Worker operator input exceeds its bounded file size.');
            }
            $documents[$name] = $contents;
        }
        $manifest = json_decode($documents['manifest'], true, flags: JSON_THROW_ON_ERROR);
        if (in_array($action, ['status', 'recover'], true)) {
            $layout = $record['layout'] ?? null;
            if ($layout === null) {
                $image = json_decode($docker->run(['image', 'inspect', $record['operator_image']])->stdout, true, flags: JSON_THROW_ON_ERROR)[0] ?? null;
                $root = $image['Config']['WorkingDir'] ?? null;
                if (!is_string($root) || !in_array('APP_HOME=' . $root, $image['Config']['Env'] ?? [], true)
                    || ($image['Id'] ?? null) !== $record['operator_image']) {
                    throw new WorkflowError('Cannot establish the saved operator image runtime paths for retirement. Preserve its ownership record.');
                }
                $layout = ['version' => 1, 'root' => $root];
            }
            array_push($arguments, '--read-only', ...self::retirementMountArguments($manifest, \Baander\Tooling\Configuration\ApplicationLayout::fromArray($layout)->root));
        } else {
            array_push($arguments, ...self::sourceMountArguments($manifest));
        }
        $decoder = <<<'CODE'
try {
$input = stream_get_contents(STDIN, 32769);
if ($input === false || strlen($input) > 32768) { exit(1); }
$documents = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
if (!is_array($documents) || array_keys($documents) !== ['manifest', 'credentials']) { exit(1); }
umask(0077);
foreach ($documents as $name => $contents) {
    if (!is_string($contents) || strlen($contents) > 8192) { exit(1); }
    $file = fopen('/operator/' . $name . '.json', 'xb');
    if ($file === false || fwrite($file, $contents) !== strlen($contents) || !fclose($file)) { exit(1); }
}
} catch (Throwable) { exit(1); }
CODE;
        array_push(
            $arguments,
            $record['operator_image'],
            '-ec',
            'php -r ' . escapeshellarg($decoder) . '; exec php "${APP_HOME:?}/bin/worker-deployment.php" "$1" /operator/manifest.json /operator/credentials.json',
            'worker-controller',
            $action,
        );
        $payload = json_encode(
            $documents,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (strlen($payload) > 32768) {
            throw new WorkflowError('Worker operator input exceeds its bounded transfer size.');
        }
        $result = \Baander\Tooling\Resources\DisposableContainer::run($docker, $record['directory'] . '/operator-' . hash('sha256', $action) . '-resources.json', $arguments, $this->connections->environment(), $record['cleanup_budget'] ?? 120.0, input: $payload, timeout: 240, check: false);
        try {
            $response = ($result->stdout
                |> (static fn(string $output): mixed => json_decode($output, true, flags: JSON_THROW_ON_ERROR)));
            if (
                $result->exitCode !== 0
                || !is_array($response)
                || ($response['success'] ?? null) !== true
                || ($response['action'] ?? null) !== $action
                || !is_array($response['result'] ?? null)
            ) {
                throw new \RuntimeException('Unconfirmed operator result', $result->exitCode);
            }
            return $response['result'];
        } catch (\Throwable $error) {
            $log = $record['directory'] . '/operator-' . hash('sha256', $action) . '.log';
            $recorded = $this->writeDiagnostic($log, $result, $documents['credentials']);
            throw new WorkflowError(
                'Worker ' . $action . ' was not confirmed; inspect ' . ($recorded ? $log : 'status') . ' and use explicit recovery.',
                previous: $error,
            );
        }
    }

    private function writeDiagnostic(string $path, ProcessResult $result, string $credentials): bool
    {
        try {
            $document = json_decode($credentials, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($document)) {
                return false;
            }
            $values = [];
            array_walk_recursive($document, static function (mixed $value) use (&$values): void {
                if (is_string($value) && $value !== '') {
                    $values[] = $value;
                    $values[] = substr(json_encode($value, JSON_THROW_ON_ERROR), 1, -1);
                }
            });
            $redactions = array_fill_keys($values, '[redacted]');
            $streams = array_map(static fn(string $stream): string => substr(strtr($stream, $redactions), 0, 32700), [$result->stdout, $result->stderr]);
            AtomicFile::write($path, 'Exit status: ' . $result->exitCode . "\nStdout:\n" . $streams[0] . "\nStderr:\n" . $streams[1]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $instance */
    public function running(array $instance): bool
    {
        $record = $instance['workers'] ?? null;
        if (!$record) {
            return false;
        }
        $manifest = AtomicFile::readJson($record['directory'] . '/manifest.json');
        if ($manifest['namespace'] !== $instance['project'] || $manifest['bootId'] !== $record['boot_id']) {
            throw new WorkflowError('Worker observation identity differs from the saved manifest.');
        }
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        $output = ($docker->run([
            'ps',
            '--all',
            '--quiet',
            '--no-trunc',
            '--filter',
            'label=app.baander.worker.namespace=' . $manifest['namespace'],
            '--filter',
            'label=app.baander.worker.boot-id=' . $manifest['bootId'],
        ])->stdout
            |> trim(...));
        if ($output === '') {
            return false;
        }
        $identifiers = preg_split('/\s+/', $output);
        $containers = ($docker->run(['inspect', ...$identifiers])->stdout
            |> (static fn(string $output): mixed => json_decode($output, true, flags: JSON_THROW_ON_ERROR)));
        $observed = [];
        $running = true;
        foreach ($containers as $container) {
            $labels = $container['Config']['Labels'];
            $role = $labels['app.baander.worker.role'] ?? null;
            if (
                ($labels['app.baander.worker.namespace'] ?? null) !== $manifest['namespace']
                || ($labels['app.baander.worker.boot-id'] ?? null) !== $manifest['bootId']
                || !in_array($role, ['control', 'execution'], true)
                || in_array($role, $observed, true)
                || $container['Image'] !== $manifest['imageId']
            ) {
                throw new WorkflowError('Worker observation does not identify the recorded boot pair.');
            }
            $observed[] = $role;
            $state = $container['State']['Running'];
            if (!is_bool($state)) {
                throw new WorkflowError('Worker container state was not confirmed.');
            }
            $running = $running && $state;
        }
        return count($observed) === 2 && $running;
    }

    /** @param array<string,mixed> $instance */
    public function phase(array &$instance, string $phase): void
    {
        $instance['workers']['phase'] = $phase;
        $this->store->save($instance['id'], $instance);
    }

    /** @param array<string,mixed> $instance */
    public function start(array &$instance): void
    {
        if (($instance['workers']['phase'] ?? 'prepared') !== 'prepared') {
            throw new WorkflowError('Worker lifecycle was already attempted; reconcile or recover the saved boot.');
        }
        $this->phase($instance, 'create_unconfirmed');
        $this->invoke($instance, 'create');
        $this->phase($instance, 'start_unconfirmed');
        $this->invoke($instance, 'start');
        $this->phase($instance, 'started');
    }

    /** @param array<string,mixed> $instance */
    public function ready(array &$instance, float $timeout = 90): void
    {
        $deadline = hrtime(true) / 1e9 + $timeout;
        while (true) {
            try {
                $result = $this->invoke($instance, 'readiness');
                if (($result['readiness'] ?? null) !== 'ready') {
                    throw new WorkflowError('Worker readiness was not confirmed.');
                }
                $this->phase($instance, 'ready');
                return;
            } catch (WorkflowError|\Baander\Tooling\Process\CommandFailure $error) {
                if ($error instanceof \Baander\Tooling\Process\CommandFailure && ($error->getCode() === 124 || $error->result?->cancelled || $error->result?->signal !== null)) {
                    throw $error;
                }
                if (hrtime(true) / 1e9 >= $deadline) {
                    throw $error;
                }
                usleep(2_000_000);
            }
        }
    }

    /** @param array<string,mixed> $instance */
    private function retireUnadmitted(array &$instance): void
    {
        $record = $instance['workers'];
        $proof = $record['preparation'];
        $directory = $record['directory'];
        if (($proof['version'] ?? null) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $record['boot_id']) !== 1
            || $record['ipc_volume'] !== $instance['project'] . '-ipc-' . $record['boot_id']
            || $directory !== $this->store->directory($instance['id']) . '/workers/' . $record['boot_id']) {
            throw new WorkflowError('Worker preparation ownership is not verified; preserve its inputs.');
        }
        foreach (['manifest', 'credentials'] as $name) {
            $path = $directory . '/' . $name . '.json';
            PrivateDirectory::assertNoSymlinks($path);
            if (!is_file($path) || ($proof[$name] ?? null) !== hash_file('sha256', $path)) {
                throw new WorkflowError('Worker preparation inputs changed; preserve its ownership record.');
            }
        }
        $manifest = AtomicFile::readJson($directory . '/manifest.json');
        if (($manifest['namespace'] ?? null) !== $instance['project']
            || ($manifest['bootId'] ?? null) !== $record['boot_id']
            || ($manifest['daemonId'] ?? null) !== $instance['docker']['daemonId']
            || ($manifest['imageId'] ?? null) !== ($proof['worker_image'] ?? null)
            || ($manifest['ipcVolume'] ?? null) !== $record['ipc_volume']) {
            throw new WorkflowError('Worker preparation identity differs from its verified inputs.');
        }
        $docker = $this->connections->client($instance['docker']);
        $docker->validateIdentity();
        if (trim($docker->run(['ps', '-aq', '--filter', 'label=app.baander.worker.namespace=' . $manifest['namespace'],
            '--filter', 'label=app.baander.worker.boot-id=' . $manifest['bootId']])->stdout) !== '') {
            throw new WorkflowError('Unadmitted worker containers require investigation before preparation cleanup.');
        }
        $this->cleanupIpc($instance);
        $this->phase($instance, 'retired');
    }

    /** @param array<string,mixed> $instance */
    private function cleanupIpc(array $instance): void
    {
        $record = $instance['workers'];
        $deadline = hrtime(true) / 1e9 + ($record['cleanup_budget'] ?? 120.0);
        foreach (['ipc-initialize-resources.json', 'ipc-resources.json'] as $name) {
            $path = $record['directory'] . '/' . $name;
            PrivateDirectory::assertNoSymlinks($path);
            if (!file_exists($path)) {
                continue;
            }
            $receipt = AtomicFile::readJson($path);
            if (!Connections::sameTarget($receipt['target'] ?? [], $instance['docker'])) {
                throw new WorkflowError('Worker IPC ownership differs from the saved Docker target.');
            }
            if ($name === 'ipc-resources.json') {
                foreach ($receipt['resources'] as $resource) {
                    if ($resource['kind'] !== 'volume' || $resource['name'] !== $record['ipc_volume']) {
                        throw new WorkflowError('Worker IPC receipt includes unrelated resources; preserve it for investigation.');
                    }
                }
            }
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                throw new WorkflowError('Worker IPC cleanup exhausted its shared budget; preserve the remaining receipts.');
            }
            $cleanup = ResourceLedger::recover($path, $this->connections->environment(), $remaining);
            if (!$cleanup->successful()) {
                throw new WorkflowError($cleanup->message());
            }
        }
    }

    /** @param array<string,mixed> $instance */
    public function retire(array &$instance): void
    {
        if (empty($instance['workers']) || ($instance['workers']['phase'] ?? null) === 'retired') {
            return;
        }
        if (in_array($instance['workers']['phase'] ?? null, ['preparing', 'prepared'], true) && isset($instance['workers']['preparation'])) {
            $this->retireUnadmitted($instance);
            return;
        }
        $this->phase($instance, 'retirement_unconfirmed');
        $status = $this->invoke($instance, 'status');
        if (
            ($status['namespace'] ?? null) === ($instance['project'] ?? null)
            && ($status['bootId'] ?? null) === ($instance['workers']['boot_id'] ?? null)
            && ($status['retirement'] ?? null) === 'unregistered'
            && ($status['reservation'] ?? null) === 'none'
            && ($status['units'] ?? null) === []
        ) {
            $manifest = AtomicFile::readJson($instance['workers']['directory'] . '/manifest.json');
            if (
                ($manifest['namespace'] ?? null) !== $instance['project']
                || ($manifest['bootId'] ?? null) !== $instance['workers']['boot_id']
            ) {
                throw new WorkflowError('Worker cleanup identity differs from the saved manifest.');
            }
            $docker = $this->connections->client($instance['docker']);
            $docker->validateIdentity();
            if (($docker->run([
                'ps',
                '-aq',
                '--filter',
                'label=app.baander.worker.namespace=' . $manifest['namespace'],
                '--filter',
                'label=app.baander.worker.boot-id=' . $manifest['bootId'],
            ])->stdout
                |> trim(...)) !== '') {
                throw new WorkflowError('Unregistered worker containers require explicit investigation before cleanup.');
            }
            $this->cleanupIpc($instance);
            $this->phase($instance, 'retired');
            return;
        }
        $result = $this->invoke($instance, 'recover');
        if (($result['state'] ?? null) !== 'retired') {
            throw new WorkflowError('Worker retirement was not confirmed; the instance remains blocked.');
        }
        $this->cleanupIpc($instance);
        $this->phase($instance, 'retired');
    }
}
