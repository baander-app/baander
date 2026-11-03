<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

use Baander\Tooling\Process\{CommandExecutor, ProcessResult};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;
use Baander\Tooling\Resources\{ResourceKind, ResourceLedger};

class WorkerLifecycle
{
    /** @param array<string,string> $environment */
    public function __construct(private readonly WorkerStateStore $store, private readonly CommandExecutor $runner, private readonly array $environment) {}

    /** @param array<string,mixed>|null $record */
    public function prepare(?array &$record, WorkerConfiguration $configuration): void
    {
        if ($record && ($record['phase'] ?? null) !== 'retired') {
            throw new \RuntimeException('Retire the saved worker deployment before creating a fresh boot.');
        }
        if ($record !== null) {
            $this->cleanupControllerTag($record);
        }
        $docker = new \Baander\Tooling\Docker\Client($this->runner, \Baander\Tooling\Docker\Target::fromArray($configuration->target), $this->environment);
        $information = $docker->validateIdentity();
        $minimumMemory = $configuration->profile->controlMemoryMiB + $configuration->profile->executionMemoryMiB + ($configuration->operatorMemoryMiB ?? 0);
        if (($information['NCPU'] ?? 0) < 2 || ($information['MemTotal'] ?? 0) < $minimumMemory * 1024 ** 2) {
            throw new \RuntimeException('A worker pair requires at least two CPUs and ' . $minimumMemory . ' MiB of daemon RAM.');
        }
        if (max([...$configuration->controlCpuSet, ...$configuration->executionCpuSet]) >= $information['NCPU']) {
            throw new \RuntimeException('Worker CPU sets exceed the Docker daemon CPU count.');
        }
        $endpoint = $docker->target->endpoint;
        if (!str_starts_with($endpoint, 'unix://')) {
            throw new \RuntimeException('The local worker controller requires a daemon-visible Unix socket.');
        }
        $boot = bin2hex(random_bytes(16));
        $directory = $this->store->directory() . '/' . $boot;
        PrivateDirectory::prepare($directory);
        $home = $configuration->layout->root;
        $cleanupSeconds = $configuration->cleanupSeconds;
        $environment = $configuration->runtimeEnvironment;
        $sharedVolumes = array_values(array_unique(array_column($configuration->mediaMounts, 'volume')));
        $ipc = $configuration->namespace . '-ipc-' . $boot;
        $manifest = [
            'version' => 6,
            'sourceMountPolicy' => $configuration->sourceMountPolicy,
            'namespace' => $configuration->namespace,
            'bootId' => $boot,
            'daemonId' => $docker->target->daemonId,
            'imageId' => $configuration->workerImage,
            'network' => $configuration->network,
            'dockerBinary' => '/usr/local/bin/docker',
            'dockerEndpoint' => 'unix:///var/run/docker.sock',
            'controlMemoryMiB' => $configuration->profile->controlMemoryMiB,
            'executionMemoryMiB' => $configuration->profile->executionMemoryMiB,
            'controlNanoCpus' => 1000000000,
            'executionNanoCpus' => 1000000000,
            'controlCpuSet' => $configuration->controlCpuSet,
            'executionCpuSet' => $configuration->executionCpuSet,
            'controlPidsLimit' => $configuration->profile->controlPidsLimit,
            'executionPidsLimit' => $configuration->profile->executionPidsLimit,
            'autoscaling' => $configuration->profile->autoscaling,
            'deploymentProfile' => $configuration->profile->deployment,
            'scheduledConsoleMiB' => $configuration->profile->scheduledConsoleMiB,
            'ipcVolume' => $ipc,
            'mediaMounts' => $configuration->mediaMounts,
        ];
        $credentials = [
            'controllerDatabaseUrl' => $environment['DATABASE_URL'],
            'runtimeEnvironment' => $environment,
        ];
        foreach ([$manifest, $credentials] as $document) {
            if (strlen(json_encode(
                $document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n") > 8192) {
                throw new \RuntimeException('Worker operator input exceeds its bounded file size.');
            }
        }
        $validation = [
            '-i',
            '--network',
            'none',
            '--cgroupns',
            'private',
            '--user',
            'www-data',
            '--cap-drop',
            'ALL',
            '--security-opt',
            'no-new-privileges',
            '--entrypoint',
            'php',
            ...self::sourceMountArguments($manifest),
            ...($configuration->operatorMemoryMiB === null ? [] : [
                '--memory', $configuration->operatorMemoryMiB . 'm', '--memory-swap', $configuration->operatorMemoryMiB . 'm',
            ]),
        ];
        $measurement = $this->operatorMeasurement(['directory' => $directory, 'namespace' => $configuration->namespace, 'boot_id' => $boot], 'prepare', $configuration->operatorImage);
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
        $validator = $measurement['bootstrap'] . "\n" . $validator;
        $payload = json_encode(
            ['manifest' => $manifest, 'environment' => $credentials['runtimeEnvironment']],
            JSON_THROW_ON_ERROR,
        );
        if (strlen($payload) > 32768) {
            throw new \RuntimeException('Worker operator input exceeds its bounded transfer size.');
        }
        $result = \Baander\Tooling\Resources\DisposableContainer::run($docker, $directory . '/validation-resources.json', [...$validation, $configuration->operatorImage, '-r', $validator, $home], $this->environment, $cleanupSeconds, input: $payload, check: false, onCompletion: $measurement['onCompletion']);
        if ($result->exitCode !== 0) {
            $log = $directory . '/validation.log';
            $recorded = $this->writeDiagnostic($log, $result, json_encode($credentials, JSON_THROW_ON_ERROR));
            throw new \RuntimeException('Worker validation failed' . ($recorded ? '; inspect ' . $log . '.' : '.'));
        }
        AtomicFile::writeJson($directory . '/manifest.json', $manifest);
        AtomicFile::writeJson($directory . '/credentials.json', $credentials);
        $record = [
            'namespace' => $configuration->namespace,
            'network' => $configuration->network,
            'target' => $configuration->target,
            'directory' => $directory,
            'operator_image' => $configuration->operatorImage,
            'controller_tag' => 'baander-worker-controller:' . $configuration->namespace . '-' . $boot,
            'controller_tag_cleaned' => false,
            'layout' => $configuration->layout->toArray(),
            'socket' => substr($endpoint, strlen('unix://')),
            'phase' => 'preparing',
            'boot_id' => $boot,
            'ipc_volume' => $ipc,
            'cleanup_budget' => $cleanupSeconds,
            'operator_memory_mib' => $configuration->operatorMemoryMiB,
            'preparation' => [
                'version' => 1,
                'manifest' => hash_file('sha256', $directory . '/manifest.json'),
                'credentials' => hash_file('sha256', $directory . '/credentials.json'),
                'worker_image' => $configuration->workerImage,
            ],
        ];
        $this->store->save($record);
        foreach ($sharedVolumes as $name) {
            $docker->run(['volume', 'create', ...$configuration->labelArguments(), $name]);
        }
        new ResourceLedger($directory . '/ipc-resources.json', $docker, $this->environment)->create(
            ResourceKind::Volume,
            $ipc,
            $configuration->labelArguments(),
        );
        $measurement = $this->operatorMeasurement($record, 'ipc-initialize', $configuration->workerImage);
        $initializeIpc = <<<'CODE'
$directory = '/run/baander-worker';
$user = posix_getpwnam('www-data');
if ($user === false || !chmod($directory, 0700)
    || !chgrp($directory, $user['gid']) || !chown($directory, $user['uid'])) { exit(1); }
CODE;
        $initializeIpc = $measurement['bootstrap'] . "\n" . $initializeIpc;
        \Baander\Tooling\Resources\DisposableContainer::run($docker, $directory . '/ipc-initialize-resources.json', [
            ...($configuration->operatorMemoryMiB === null ? [] : [
                '--memory', $configuration->operatorMemoryMiB . 'm', '--memory-swap', $configuration->operatorMemoryMiB . 'm',
            ]),
            '--network',
            'none',
            '--cgroupns',
            'private',
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
            $configuration->workerImage,
            '-r',
            $initializeIpc,
        ], $this->environment, $cleanupSeconds, onCompletion: $measurement['onCompletion']);
        $docker->run([
            'image',
            'tag',
            $configuration->operatorImage,
            $record['controller_tag'],
        ]);
        $this->phase($record, 'prepared');
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
                throw new \RuntimeException('The recorded source directory changed; reconcile the saved worker deployment.');
            }
            if (str_contains($source, ',')) {
                throw new \RuntimeException('Docker mount paths cannot contain commas.');
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
                throw new \RuntimeException('Retirement source declarations require canonical saved read-only directory paths.');
            }
            foreach ([$applicationRoot, '/operator', '/var/run/docker.sock', '/run/docker.sock', '/etc', '/proc', '/sys', '/dev', '/bin', '/sbin', '/usr', '/lib', '/lib64'] as $reserved) {
                if ($source === $reserved || str_starts_with($source, $reserved . '/') || str_starts_with($reserved, $source . '/')) {
                    throw new \RuntimeException('Retirement source placeholder overlaps helper runtime state: ' . $source);
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

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function invoke(array $record, string $action): array
    {
        if ($action === 'create' && isset($record['preparation'])) {
            $saved = $this->store->load() ?? [];
            if (($saved['phase'] ?? null) !== 'create_unconfirmed' || ($saved['boot_id'] ?? null) !== $record['boot_id']) {
                throw new \RuntimeException('Worker admission requires durable creation intent for this boot.');
            }
        }
        $docker = new \Baander\Tooling\Docker\Client($this->runner, \Baander\Tooling\Docker\Target::fromArray($record['target']), $this->environment);
        $docker->validateIdentity();
        // The trusted controller needs read access to retained Composer trees owned by www-data.
        // Preserve the daemon socket's existing SELinux label.
        $arguments = [
            '-i',
            '--network',
            $record['network'],
            '--cgroupns',
            'private',
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
        if (($record['operator_memory_mib'] ?? null) !== null) {
            array_push($arguments, '--memory', $record['operator_memory_mib'] . 'm', '--memory-swap', $record['operator_memory_mib'] . 'm');
        }
        $documents = [];
        foreach (['manifest', 'credentials'] as $name) {
            $contents = @file_get_contents($record['directory'] . '/' . $name . '.json', length: 8193);
            if ($contents === false) {
                throw new \RuntimeException('Worker operator inputs are missing or unreadable; preserve the saved ownership record.');
            }
            if (strlen($contents) > 8192) {
                throw new \RuntimeException('Worker operator input exceeds its bounded file size.');
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
                    throw new \RuntimeException('Cannot establish the saved operator image runtime paths for retirement. Preserve its ownership record.');
                }
                $layout = ['version' => 1, 'root' => $root];
            }
            array_push($arguments, '--read-only', ...self::retirementMountArguments($manifest, \Baander\Tooling\Configuration\ApplicationLayout::fromArray($layout)->root));
        } else {
            array_push($arguments, ...self::sourceMountArguments($manifest));
        }
        $measurement = $this->operatorMeasurement($record, $action, $record['operator_image']);
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
        $bootstrap = "<?php\n" . $measurement['bootstrap'] . "\n";
        $decoder .= "\nif (file_put_contents('/operator/measurement.php', base64_decode(" . var_export(base64_encode($bootstrap), true) . ")) === false) { exit(1); }\n";
        array_push(
            $arguments,
            $record['operator_image'],
            '-ec',
            'php -r ' . escapeshellarg($decoder) . '; exec php -d auto_prepend_file=/operator/measurement.php "${APP_HOME:?}/bin/worker-deployment.php" "$1" /operator/manifest.json /operator/credentials.json',
            'worker-controller',
            $action,
        );
        $payload = json_encode(
            $documents,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (strlen($payload) > 32768) {
            throw new \RuntimeException('Worker operator input exceeds its bounded transfer size.');
        }
        $result = \Baander\Tooling\Resources\DisposableContainer::run($docker, $record['directory'] . '/operator-' . hash('sha256', $action) . '-resources.json', $arguments, $this->environment, $record['cleanup_budget'] ?? 120.0, input: $payload, timeout: 240, check: false, onCompletion: $measurement['onCompletion']);
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
            throw new \RuntimeException(
                'Worker ' . $action . ' was not confirmed; inspect ' . ($recorded ? $log : 'status') . ' and use explicit recovery.',
                previous: $error,
            );
        }
    }

    /** @param array<string,mixed> $context
     * @return array{bootstrap:string,onCompletion:\Closure,path:string}
     */
    private function operatorMeasurement(array $context, string $action, string $image): array
    {
        $context['directory'] .= '/operator-measurements';
        return ContainerMeasurement::prepare($context, $action, $image);
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

    /** @param array<string,mixed> $record */
    public function running(?array $record): bool
    {
        if (!$record) {
            return false;
        }
        $manifest = AtomicFile::readJson($record['directory'] . '/manifest.json');
        if ($manifest['namespace'] !== $record['namespace'] || $manifest['bootId'] !== $record['boot_id']) {
            throw new \RuntimeException('Worker observation identity differs from the saved manifest.');
        }
        $docker = new \Baander\Tooling\Docker\Client($this->runner, \Baander\Tooling\Docker\Target::fromArray($record['target']), $this->environment);
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
                throw new \RuntimeException('Worker observation does not identify the recorded boot pair.');
            }
            $observed[] = $role;
            $state = $container['State']['Running'];
            if (!is_bool($state)) {
                throw new \RuntimeException('Worker container state was not confirmed.');
            }
            $running = $running && $state;
        }
        return count($observed) === 2 && $running;
    }

    /** @param array<string,mixed> $record */
    public function phase(array &$record, string $phase): void
    {
        $record['phase'] = $phase;
        $this->store->save($record);
    }

    /** @param array<string,mixed> $record */
    public function start(array &$record): void
    {
        if (($record['phase'] ?? 'prepared') !== 'prepared') {
            throw new \RuntimeException('Worker lifecycle was already attempted; reconcile or recover the saved boot.');
        }
        $this->phase($record, 'create_unconfirmed');
        $this->invoke($record, 'create');
        $this->phase($record, 'start_unconfirmed');
        $this->invoke($record, 'start');
        $this->phase($record, 'started');
    }

    /** @param array<string,mixed> $record */
    public function ready(array &$record, float $timeout = 90): void
    {
        $deadline = hrtime(true) / 1e9 + $timeout;
        while (true) {
            try {
                $result = $this->invoke($record, 'readiness');
                if (($result['readiness'] ?? null) !== 'ready') {
                    throw new \RuntimeException('Worker readiness was not confirmed.');
                }
                $this->phase($record, 'ready');
                return;
            } catch (\RuntimeException $error) {
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

    /** @param array<string,mixed> $record */
    private function retireUnadmitted(array &$record): void
    {
        $proof = $record['preparation'];
        $directory = $record['directory'];
        if (($proof['version'] ?? null) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $record['boot_id']) !== 1
            || $record['ipc_volume'] !== $record['namespace'] . '-ipc-' . $record['boot_id']
            || $directory !== $this->store->directory() . '/' . $record['boot_id']) {
            throw new \RuntimeException('Worker preparation ownership is not verified; preserve its inputs.');
        }
        foreach (['manifest', 'credentials'] as $name) {
            $path = $directory . '/' . $name . '.json';
            PrivateDirectory::assertNoSymlinks($path);
            if (!is_file($path) || ($proof[$name] ?? null) !== hash_file('sha256', $path)) {
                throw new \RuntimeException('Worker preparation inputs changed; preserve its ownership record.');
            }
        }
        $manifest = AtomicFile::readJson($directory . '/manifest.json');
        if (($manifest['namespace'] ?? null) !== $record['namespace']
            || ($manifest['bootId'] ?? null) !== $record['boot_id']
            || ($manifest['daemonId'] ?? null) !== $record['target']['daemonId']
            || ($manifest['imageId'] ?? null) !== ($proof['worker_image'] ?? null)
            || ($manifest['ipcVolume'] ?? null) !== $record['ipc_volume']) {
            throw new \RuntimeException('Worker preparation identity differs from its verified inputs.');
        }
        $docker = new \Baander\Tooling\Docker\Client($this->runner, \Baander\Tooling\Docker\Target::fromArray($record['target']), $this->environment);
        $docker->validateIdentity();
        if (trim($docker->run(['ps', '-aq', '--filter', 'label=app.baander.worker.namespace=' . $manifest['namespace'],
            '--filter', 'label=app.baander.worker.boot-id=' . $manifest['bootId']])->stdout) !== '') {
            throw new \RuntimeException('Unadmitted worker containers require investigation before preparation cleanup.');
        }
        $this->cleanupIpc($record);
        $this->phase($record, 'retired');
        $this->cleanupControllerTag($record);
    }

    /** @param array<string,mixed> $record */
    private function cleanupIpc(array $record): void
    {
        $deadline = hrtime(true) / 1e9 + ($record['cleanup_budget'] ?? 120.0);
        foreach (['ipc-initialize-resources.json', 'ipc-resources.json'] as $name) {
            $path = $record['directory'] . '/' . $name;
            PrivateDirectory::assertNoSymlinks($path);
            if (!file_exists($path)) {
                continue;
            }
            $receipt = AtomicFile::readJson($path);
            if (!self::sameTarget($receipt['target'] ?? [], $record['target'])) {
                throw new \RuntimeException('Worker IPC ownership differs from the saved Docker target.');
            }
            if ($name === 'ipc-resources.json') {
                foreach ($receipt['resources'] as $resource) {
                    if ($resource['kind'] !== 'volume' || $resource['name'] !== $record['ipc_volume']) {
                        throw new \RuntimeException('Worker IPC receipt includes unrelated resources; preserve it for investigation.');
                    }
                }
            }
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                throw new \RuntimeException('Worker IPC cleanup exhausted its shared budget; preserve the remaining receipts.');
            }
            $cleanup = ResourceLedger::recover($path, $this->environment, $remaining);
            if (!$cleanup->successful()) {
                throw new \RuntimeException($cleanup->message());
            }
        }
    }

    /** @param array<string,mixed> $record */
    private function cleanupControllerTag(array &$record): void
    {
        if (($record['controller_tag_cleaned'] ?? false) === true) {
            return;
        }
        $suffix = ($record['namespace'] ?? '') . '-' . ($record['boot_id'] ?? '');
        $tag = 'baander-worker-controller:' . $suffix;
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/D', $suffix) !== 1
            || (isset($record['controller_tag']) && $record['controller_tag'] !== $tag)) {
            throw new \RuntimeException('Worker controller tag does not match the recorded boot.');
        }
        $docker = new \Baander\Tooling\Docker\Client($this->runner, \Baander\Tooling\Docker\Target::fromArray($record['target']), $this->environment);
        $docker->validateIdentity();
        $inspect = static function () use ($docker, $tag): ?array {
            $result = $docker->run(['image', 'inspect', $tag], check: false);
            if ($result->exitCode !== 0) {
                if ($result->exitCode !== 1 || trim($result->stderr) !== 'Error response from daemon: No such image: ' . $tag) {
                    throw new \RuntimeException('Worker controller tag absence was not confirmed.');
                }
                if (trim($docker->run(['image', 'ls', '--all', '--no-trunc', '--filter', 'reference=' . $tag, '--format', '{{.Repository}}:{{.Tag}}'])->stdout) !== '') {
                    throw new \RuntimeException('Worker controller tag remains in the daemon image inventory.');
                }
                return null;
            }
            $images = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($images) || !array_is_list($images) || count($images) !== 1 || !is_array($images[0])) {
                throw new \RuntimeException('Worker controller tag identity was not confirmed.');
            }
            return $images[0];
        };
        $image = $inspect();
        if ($image !== null) {
            if (($image['Id'] ?? null) !== $record['operator_image']) {
                throw new \RuntimeException('Worker controller tag no longer identifies the recorded operator image.');
            }
            $docker->run(['image', 'rm', $tag]);
            if ($inspect() !== null) {
                throw new \RuntimeException('Worker controller tag remains after removal.');
            }
        }
        $record['controller_tag'] = $tag;
        $record['controller_tag_cleaned'] = true;
        $this->store->save($record);
    }

    /** @param array<string,mixed> $first
     * @param array<string,mixed> $second
     */
    private static function sameTarget(array $first, array $second): bool
    {
        $a = \Baander\Tooling\Docker\Target::fromArray($first);
        $b = \Baander\Tooling\Docker\Target::fromArray($second);
        $files = static function (array $items): array {
            $result = [];
            foreach ($items as $path => $digest) {
                $result[basename($path)] = $digest;
            }
            ksort($result);
            return $result;
        };
        return $a->executable === $b->executable && $a->endpoint === $b->endpoint && $a->daemonId === $b->daemonId
            && $files($a->files) === $files($b->files) && ($a->transportArguments[0] ?? null) === ($b->transportArguments[0] ?? null);
    }

    /** @param array<string,mixed> $record */
    public function retire(array &$record): void
    {
        if (empty($record)) {
            return;
        }
        if (($record['phase'] ?? null) === 'retired') {
            $this->cleanupControllerTag($record);
            return;
        }
        if (in_array($record['phase'] ?? null, ['preparing', 'prepared'], true) && isset($record['preparation'])) {
            $this->retireUnadmitted($record);
            return;
        }
        $this->phase($record, 'retirement_unconfirmed');
        $status = $this->invoke($record, 'status');
        if (
            ($status['namespace'] ?? null) === ($record['namespace'] ?? null)
            && ($status['bootId'] ?? null) === ($record['boot_id'] ?? null)
            && ($status['retirement'] ?? null) === 'unregistered'
            && ($status['reservation'] ?? null) === 'none'
            && ($status['units'] ?? null) === []
        ) {
            $manifest = AtomicFile::readJson($record['directory'] . '/manifest.json');
            if (
                ($manifest['namespace'] ?? null) !== $record['namespace']
                || ($manifest['bootId'] ?? null) !== $record['boot_id']
            ) {
                throw new \RuntimeException('Worker cleanup identity differs from the saved manifest.');
            }
            $docker = new \Baander\Tooling\Docker\Client($this->runner, \Baander\Tooling\Docker\Target::fromArray($record['target']), $this->environment);
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
                throw new \RuntimeException('Unregistered worker containers require explicit investigation before cleanup.');
            }
            $this->cleanupIpc($record);
            $this->phase($record, 'retired');
            $this->cleanupControllerTag($record);
            return;
        }
        $result = $this->invoke($record, 'recover');
        if (($result['state'] ?? null) !== 'retired') {
            throw new \RuntimeException('Worker retirement was not confirmed; the instance remains blocked.');
        }
        $this->cleanupIpc($record);
        $this->phase($record, 'retired');
        $this->cleanupControllerTag($record);
    }
}
