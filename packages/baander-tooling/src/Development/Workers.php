<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;

class Workers
{
    public function __construct(private readonly StateStore $store, private readonly Runner $runner) {}

    /** @param array<string,mixed> $instance
     * @param array<string,mixed> $release
     */
    public function prepare(array &$instance, array $release): void
    {
        $previous = $instance['workers'] ?? null;
        if ($previous && ($previous['phase'] ?? null) !== 'retired') {
            throw new WorkflowError('Retire the saved worker deployment before creating a fresh boot.');
        }
        $docker = new Docker($this->runner, $instance['docker']);
        $information = $docker->validate();
        if (($information['NCPU'] ?? 0) < 2 || ($information['MemTotal'] ?? 0) < 8448 * 1024 ** 2) {
            throw new WorkflowError('A worker pair requires at least two CPUs and 8448 MiB of daemon RAM.');
        }
        $endpoint = ($docker->run(['context', 'inspect', $instance['docker']['context']])->stdout
            |> (static fn(string $output): mixed => json_decode($output, true, flags: JSON_THROW_ON_ERROR)))[0]['Endpoints']['docker']['Host'];
        if (!str_starts_with($endpoint, 'unix://')) {
            throw new WorkflowError('The local worker controller requires a daemon-visible Unix socket.');
        }
        $boot = bin2hex(random_bytes(16));
        $directory = $this->store->directory($instance['id']) . '/workers/' . $boot;
        PrivateDirectory::prepare($directory);
        $profile = ($docker->run([
            'run',
            '--rm',
            '--network',
            'none',
            '--entrypoint',
            'php',
            $release['worker_image'],
            '-r',
            'require "vendor/autoload.php"; echo (new \\App\\Shared\\Application\\DTO\\WorkerAutoscalingProfile())->toJson();',
        ])->stdout
            |> (static fn(string $output): mixed => json_decode($output, true, flags: JSON_THROW_ON_ERROR)));
        $mounts = [];
        foreach ($instance['media'] ?? [] as $item) {
            $mounts[] = [
                'source' => Environment::resolvedPath($item['source']),
                'target' => $item['target'],
                'role' => 'source',
                'readOnly' => true,
            ];
        }
        $runtime = new Runtime($this->store, $this->runner);
        $environment = $runtime->environment($instance);
        $configuration = $runtime->config($instance, $release);
        foreach ([
            ['media', $environment['MEDIA_STORAGE_PATH'] ?? '/storage/media', 'source'],
            ['storage', '/var/www/html/storage', 'derived'],
            ['conversions', $environment['CONVERT_STORAGE_PATH'] ?? '/storage/conversions', 'derived'],
            ['transcode_state', '/var/www/html/var/transcode_state', 'derived'],
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
                    'source' => Environment::resolvedPath($configured['source']),
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
            $docker->run([
                'volume',
                'create',
                '--label',
                'app.baander.development.owner=' . $instance['id'],
                $name,
            ]);
            $mounts[] = [
                'volume' => $name,
                'target' => $target,
                'role' => $role,
                'readOnly' => $role === 'source',
            ];
        }
        $ipc = $instance['project'] . '-ipc-' . $boot;
        $docker->run([
            'volume',
            'create',
            '--label',
            'app.baander.development.owner=' . $instance['id'],
            $ipc,
        ]);
        $initializeIpc = <<<'CODE'
$directory = '/run/baander-worker';
$user = posix_getpwnam('www-data');
if ($user === false || !chmod($directory, 0700)
    || !chgrp($directory, $user['gid']) || !chown($directory, $user['uid'])) { exit(1); }
CODE;
        $docker->run([
            'run',
            '--rm',
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
            $release['worker_image'],
            '-r',
            $initializeIpc,
        ]);
        $manifest = [
            'version' => 5,
            'sourceMountPolicy' => 'local-development',
            'namespace' => $instance['project'],
            'bootId' => $boot,
            'daemonId' => $instance['docker']['daemon_id'],
            'imageId' => $release['worker_image'],
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
            'run',
            '--rm',
            '-i',
            '--network',
            'none',
            '--user',
            '0',
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
require '/var/www/html/vendor/autoload.php';
$input = stream_get_contents(STDIN, 32769);
if ($input === false || strlen($input) > 32768) { exit(1); }
$document = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
$manifest = \App\Shared\Infrastructure\Worker\DeploymentOperatorManifest::fromJson(json_encode($document['manifest'], JSON_THROW_ON_ERROR));
$environment = new \App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment($document['environment']);
foreach (\App\Shared\Application\DTO\DeploymentUnit::cases() as $unit) { $manifest->recipe($unit, $environment); }
} catch (Throwable) { exit(1); }
CODE;
        $payload = json_encode(
            ['manifest' => $manifest, 'environment' => $credentials['runtimeEnvironment']],
            JSON_THROW_ON_ERROR,
        );
        if (strlen($payload) > 32768) {
            throw new WorkflowError('Worker operator input exceeds its bounded transfer size.');
        }
        $result = $docker->run(
            [...$validation, $release['operator_image'], '-r', $validator],
            input: $payload,
            check: false,
        );
        if ($result->exitCode !== 0) {
            throw new WorkflowError('Worker shared storage or runtime configuration is invalid; remove overlapping media paths and reconcile adopted storage before enabling workers.');
        }
        $docker->run([
            'image',
            'tag',
            $release['operator_image'],
            'baander-development-controller:' . $instance['project'] . '-' . $boot,
        ]);
        AtomicFile::writeJson($directory . '/manifest.json', $manifest);
        AtomicFile::writeJson($directory . '/credentials.json', $credentials);
        $instance['workers'] = [
            'directory' => $directory,
            'operator_image' => $release['operator_image'],
            'socket' => substr($endpoint, strlen('unix://')),
            'phase' => 'prepared',
            'boot_id' => $boot,
            'ipc_volume' => $ipc,
        ];
        $this->store->save($instance['id'], $instance);
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

    /** @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    public function invoke(array $instance, string $action): array
    {
        $record = $instance['workers'];
        $docker = new Docker($this->runner, $instance['docker']);
        $docker->validate();
        // Preserve the daemon socket's existing SELinux label in the trusted controller.
        $arguments = [
            'run',
            '--rm',
            '-i',
            '--network',
            Runtime::networkName($instance),
            '--user',
            '0',
            '--cap-drop',
            'ALL',
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
        ];
        $documents = [];
        foreach (['manifest', 'credentials'] as $name) {
            $contents = file_get_contents($record['directory'] . '/' . $name . '.json', length: 8193);
            if ($contents === false || strlen($contents) > 8192) {
                throw new WorkflowError('Worker operator input exceeds its bounded file size.');
            }
            $documents[$name] = $contents;
        }
        $manifest = json_decode($documents['manifest'], true, flags: JSON_THROW_ON_ERROR);
        array_push($arguments, ...self::sourceMountArguments($manifest));
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
            'php -r ' . escapeshellarg($decoder) . '; exec php /var/www/html/bin/worker-deployment.php "$1" /operator/manifest.json /operator/credentials.json',
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
        $result = $docker->run($arguments, input: $payload, timeout: 240, check: false);
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
                throw new \RuntimeException('Unconfirmed operator result');
            }
            return $response['result'];
        } catch (\Throwable $error) {
            throw new WorkflowError(
                'Worker ' . $action . ' was not confirmed; inspect status and use explicit recovery.',
                previous: $error,
            );
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
        $docker = new Docker($this->runner, $instance['docker']);
        $docker->validate();
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
            } catch (WorkflowError $error) {
                if (hrtime(true) / 1e9 >= $deadline) {
                    throw $error;
                }
                usleep(2_000_000);
            }
        }
    }

    /** @param array<string,mixed> $instance */
    public function retire(array &$instance): void
    {
        if (empty($instance['workers']) || ($instance['workers']['phase'] ?? null) === 'retired') {
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
            $docker = new Docker($this->runner, $instance['docker']);
            $docker->validate();
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
            $this->phase($instance, 'retired');
            return;
        }
        $result = $this->invoke($instance, 'recover');
        if (($result['state'] ?? null) !== 'retired') {
            throw new WorkflowError('Worker retirement was not confirmed; the instance remains blocked.');
        }
        $this->phase($instance, 'retired');
    }
}
