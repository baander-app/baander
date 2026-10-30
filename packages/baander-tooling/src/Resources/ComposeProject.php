<?php

declare(strict_types=1);

namespace Baander\Tooling\Resources;

use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Filesystem\{AtomicFile, FileLock, PrivateDirectory};
use Baander\Tooling\Process\{EngineExecutor, ExecutionContext, ProcessResult};

/** Owns a frozen Compose definition and its disposable resources across interruption. */
final readonly class ComposeProject
{
    private const string LABEL = 'org.baander.tooling.run';

    /** @param array<string,string> $environment */
    public function __construct(public string $receipt, private Client $docker, private array $environment) {}

    /** @param array<string,string> $environment */
    public function prepare(string $project, string $file, string $cwd, array $environment, ?string $applicationRoot = null): void
    {
        PrivateDirectory::prepare(dirname($this->receipt));
        $lock = FileLock::acquire($this->receipt . '.lock');
        try {
            if (file_exists($this->receipt) || is_link($this->receipt)) {
                throw new \RuntimeException('Compose ownership already exists; recover it first.');
            }
            if (preg_match('/^[a-z0-9][a-z0-9_-]+$/D', $project) !== 1) {
                throw new \InvalidArgumentException('Invalid owned Compose project name.');
            }
            $configuration = json_decode($this->docker->compose($project, $file, ['config', '--format', 'json'], cwd: $cwd, env: $environment)->stdout, flags: JSON_THROW_ON_ERROR);
            if (!$configuration instanceof \stdClass || !($configuration->services ?? null) instanceof \stdClass || get_object_vars($configuration->services) === []) {
                throw new \RuntimeException('Compose configuration has no services.');
            }
            if ($applicationRoot !== null) {
                $configuration->services->app->working_dir = $applicationRoot;
            }
            $token = bin2hex(random_bytes(16));
            foreach (['services', 'networks', 'volumes'] as $section) {
                foreach ($configuration->{$section} ?? [] as $name => $definition) {
                    if (!$definition instanceof \stdClass || ($definition->external ?? false)) {
                        throw new \RuntimeException('Disposable Compose resources must be owned by this project.');
                    }
                    $definition->labels ??= new \stdClass();
                    $definition->labels->{self::LABEL} = $token;
                }
            }
            $frozen = $this->receipt . '.compose.json';
            AtomicFile::writeJson($frozen, (array) $configuration);
            AtomicFile::writeJson($this->receipt, ['version' => 1, 'project' => $project, 'target' => $this->docker->target->toArray(), 'file' => $frozen, 'hash' => hash_file('sha256', $frozen), 'token' => $token, 'resources' => [], 'mountedVolumes' => []]);
        } finally {
            $lock->release();
        }
    }

    /** @param list<string> $arguments */
    public function run(array $arguments, ?string $logPath = null, bool $check = true): ProcessResult
    {
        $lock = FileLock::acquire($this->receipt . '.lock');
        try {
            $state = $this->state();
            if ($state['target'] != $this->docker->target->toArray()) {
                throw new \RuntimeException('Compose target differs from saved ownership.');
            }
            $this->docker->validateIdentity();
            $changesResources = in_array($arguments[0] ?? '', ['up', 'create', 'start', 'restart', 'run'], true);
            if ($changesResources) {
                self::validateConfiguredResources($this->docker, $state);
                self::inventory($this->docker, $state);
            }
            $result = $this->docker->compose($state['project'], $state['file'], $arguments, logPath: $logPath, check: $check);
            if ($changesResources) {
                $state['resources'] = self::inventory($this->docker, $state);
                $state['mountedVolumes'] = array_values(array_unique([...$state['mountedVolumes'], ...self::mountedVolumes($this->docker, $state)]));
                AtomicFile::writeJson($this->receipt, $state);
            }
            return $result;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $state = AtomicFile::readJson($this->receipt);
        PrivateDirectory::assertNoSymlinks($this->receipt . '.compose.json');
        if (($state['version'] ?? null) !== 1 || !is_string($state['project'] ?? null)
            || !is_string($state['token'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $state['token']) !== 1
            || ($state['file'] ?? null) !== $this->receipt . '.compose.json' || !is_file($state['file']) || !is_string($state['hash'] ?? null)
            || hash_file('sha256', $state['file']) !== $state['hash'] || !is_array($state['resources'] ?? null) || !array_is_list($state['resources'])
            || !is_array($state['mountedVolumes'] ?? null) || !array_is_list($state['mountedVolumes'])) {
            throw new \RuntimeException('Invalid Compose ownership; preserve the receipt.');
        }
        foreach ($state['resources'] as $resource) {
            if (!is_array($resource) || !in_array($resource['kind'] ?? null, ['container', 'network', 'volume'], true)
                || !is_string($resource['name'] ?? null) || !is_string($resource['id'] ?? null)) {
                throw new \RuntimeException('Invalid saved Compose resource identity.');
            }
        }
        foreach ($state['mountedVolumes'] as $volume) {
            if (!is_string($volume) || $volume === '') {
                throw new \RuntimeException('Invalid saved Compose mounted volume identity.');
            }
        }
        Target::fromArray($state['target'] ?? []);
        return $state;
    }

    /** @param array<string,mixed> $state */
    private static function validateConfiguredResources(Client $docker, array $state): void
    {
        $configuration = AtomicFile::readJson($state['file']);
        foreach (['networks' => 'network', 'volumes' => 'volume'] as $section => $kind) {
            foreach ($configuration[$section] ?? [] as $definition) {
                $name = $definition['name'] ?? null;
                if (!is_string($name) || $name === '') {
                    throw new \RuntimeException('Resolved Compose resources must declare their canonical names.');
                }
                $resource = self::inspect($docker, $kind, $name);
                if ($resource !== null && (($resource['Name'] ?? null) !== $name || ($resource['Labels'][self::LABEL] ?? null) !== $state['token'])) {
                    throw new \RuntimeException('Compose configured resource ownership does not match; refusing mutation.');
                }
            }
        }
    }

    /** @param array<string,mixed> $state
     * @return list<array{kind:string,name:string,id:string}>
     */
    private static function inventory(Client $docker, array $state): array
    {
        $resources = [];
        foreach (['container', 'network', 'volume'] as $kind) {
            $arguments = [$kind, 'ls'];
            if ($kind === 'container') {
                $arguments[] = '--all';
            }
            $listing = $docker->run([...$arguments, '--quiet', '--filter', 'label=com.docker.compose.project=' . $state['project']])->stdout;
            $names = preg_split('/\s+/', trim($listing)) ?: [];
            foreach ($state['resources'] as $saved) {
                if ($saved['kind'] === $kind) {
                    $names[] = $saved['id'];
                }
            }
            foreach (array_unique($names) as $name) {
                if ($name === '') {
                    continue;
                }
                $resource = self::inspect($docker, $kind, $name);
                if ($resource === null) {
                    continue;
                }
                $labels = $kind === 'container' ? ($resource['Config']['Labels'] ?? []) : ($resource['Labels'] ?? []);
                $id = $kind === 'volume' ? ($resource['Name'] ?? null) : ($resource['Id'] ?? $resource['ID'] ?? null);
                $canonicalName = $kind === 'container' ? ltrim($resource['Name'] ?? '', '/') : ($resource['Name'] ?? null);
                if (($labels[self::LABEL] ?? null) !== $state['token'] || !is_string($id) || $id === '' || !is_string($canonicalName) || $canonicalName === '') {
                    throw new \RuntimeException('Compose resource ownership does not match; refusing removal.');
                }
                foreach ($state['resources'] as $saved) {
                    if ($saved['kind'] === $kind && $saved['name'] === $canonicalName && $saved['id'] !== $id) {
                        throw new \RuntimeException('Compose resource identity changed; refusing removal.');
                    }
                }
                $resources[$kind . ':' . $canonicalName] = ['kind' => $kind, 'name' => $canonicalName, 'id' => $id];
            }
        }
        return array_values($resources);
    }

    /** @return array<string,mixed>|null */
    private static function inspect(Client $docker, string $kind, string $identity): ?array
    {
        $result = $docker->run([$kind, 'inspect', '--', $identity], check: false);
        if ($result->exitCode !== 0) {
            $escaped = preg_quote($identity, '/');
            $message = trim($result->stderr);
            $missing = preg_match('/^(?:Error(?: response from daemon)?: )?No such ' . $kind . ': ' . $escaped . '$/Di', $message) === 1
                || ($kind === 'network' && preg_match('/^(?:Error response from daemon: )?network ' . $escaped . ' not found$/Di', $message) === 1)
                || ($kind === 'volume' && preg_match('/^(?:Error response from daemon: )?get ' . $escaped . ': no such volume$/Di', $message) === 1);
            if ($result->exitCode !== 1 || !$missing) {
                throw new \RuntimeException('Cannot prove saved Compose resource absence: ' . $message);
            }
            $listing = [$kind, 'ls'];
            if ($kind === 'container') {
                $listing[] = '--all';
            }
            if ($kind !== 'volume') {
                $listing[] = '--no-trunc';
            }
            $identities = preg_split('/\s+/', trim($docker->run([...$listing, '--quiet'])->stdout)) ?: [];
            if (in_array($identity, $identities, true)) {
                throw new \RuntimeException('Saved Compose resource remains in daemon enumeration.');
            }
            return null;
        }
        $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_is_list($data) || count($data) !== 1 || !is_array($data[0])) {
            throw new \RuntimeException('Invalid Compose resource inspection.');
        }
        return $data[0];
    }

    /** @param array<string,mixed> $state
     * @return list<string>
     */
    private static function mountedVolumes(Client $docker, array $state): array
    {
        $volumes = [];
        foreach ($state['resources'] as $resource) {
            if ($resource['kind'] !== 'container') {
                continue;
            }
            $inspection = json_decode($docker->run(['container', 'inspect', '--', $resource['id']])->stdout, true, flags: JSON_THROW_ON_ERROR);
            $mounts = $inspection[0]['Mounts'] ?? null;
            if (!is_array($mounts)) {
                throw new \RuntimeException('Cannot inventory mounted Compose volumes.');
            }
            foreach ($mounts as $mount) {
                if (($mount['Type'] ?? null) === 'volume') {
                    if (!is_string($mount['Name'] ?? null) || $mount['Name'] === '') {
                        throw new \RuntimeException('Invalid mounted Compose volume identity.');
                    }
                    $volumes[] = $mount['Name'];
                }
            }
        }
        return array_values(array_unique($volumes));
    }

    /** @param array<string,string> $environment */
    public static function recover(string $receipt, array $environment, float $seconds): CleanupReport
    {
        $state = AtomicFile::readJson($receipt);
        $executor = new EngineExecutor(ExecutionContext::bounded($seconds, $environment, ignoreCancellation: true));
        try {
            return new self($receipt, new Client($executor, Target::fromArray($state['target'] ?? []), $environment), $environment)->cleanup($seconds);
        } finally {
            $executor->close();
        }
    }

    public function cleanup(float $seconds, ?string $logPath = null): CleanupReport
    {
        $executor = null;
        $lock = null;
        try {
            $lock = FileLock::acquire($this->receipt . '.lock');
            if (!file_exists($this->receipt) && !is_link($this->receipt)) {
                return new CleanupReport([], $this->receipt);
            }
            $state = $this->state();
            $executor = new EngineExecutor(ExecutionContext::bounded($seconds, $this->environment, ignoreCancellation: true));
            $docker = new Client($executor, Target::fromArray($state['target']), $this->environment);
            $docker->validateIdentity();
            self::validateConfiguredResources($docker, $state);
            if ($logPath !== null) {
                try {
                    $docker->compose($state['project'], $state['file'], ['logs', '--no-color'], timeout: min(10, $seconds), check: false, logPath: $logPath);
                } catch (\Throwable $error) {
                    fwrite(STDERR, 'Log collection failed: ' . $error->getMessage() . "\n");
                }
            }
            $state['resources'] = self::inventory($docker, $state);
            $state['mountedVolumes'] = array_values(array_unique([...$state['mountedVolumes'], ...self::mountedVolumes($docker, $state)]));
            AtomicFile::writeJson($this->receipt, $state);
            $docker->compose($state['project'], $state['file'], ['down', '--volumes', '--remove-orphans', '--timeout', '5']);
            if (self::inventory($docker, $state) !== []) {
                throw new \RuntimeException('Compose resources remain after cleanup.');
            }
            $volumes = $docker->run(['volume', 'ls', '--format', '{{json .}}'])->stdout;
            foreach (explode("\n", trim($volumes)) as $line) {
                if ($line === '') {
                    continue;
                }
                $volume = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (!is_string($volume['Name'] ?? null) || in_array($volume['Name'], $state['mountedVolumes'], true)) {
                    throw new \RuntimeException('Cannot prove mounted Compose volumes were removed.');
                }
            }
            if (!unlink($this->receipt)) {
                throw new \RuntimeException('Cannot remove completed Compose receipt.');
            }
            if (!unlink($state['file'])) {
                throw new \RuntimeException('Cannot remove frozen Compose configuration.');
            }
            return new CleanupReport([], $this->receipt);
        } catch (\Throwable $error) {
            return new CleanupReport([$error->getMessage()], $this->receipt);
        } finally {
            $executor?->close();
            $lock?->release();
        }
    }
}
