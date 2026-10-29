<?php

declare(strict_types=1);

namespace Baander\Tooling\Resources;

use Baander\Tooling\Docker\Client;
use Baander\Tooling\Docker\Target;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\FileLock;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;

final class ResourceLedger
{
    private const string LABEL = 'org.baander.tooling.run';

    /** @param array<string,string> $environment */
    public function __construct(public readonly string $receipt, private readonly Client $docker, private readonly array $environment) {}

    /** @param list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function create(ResourceKind $resourceKind, string $name, array $arguments, ?array $env = null): string
    {
        $kind = $resourceKind->value;
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Owned resources require a canonical name.');
        }
        $lock = FileLock::acquire($this->receipt . '.lock');
        try {
            $state = $this->state();
            if ($state['target'] != $this->docker->target->toArray()) {
                throw new \RuntimeException('Saved Docker ownership differs from the creation target.');
            }
            foreach ($state['resources'] as $resource) {
                if ($resource['kind'] === $kind && $resource['name'] === $name) {
                    throw new \RuntimeException('Resource intention already exists; recover it before creation.');
                }
            }
            $this->docker->validateIdentity();
            $state['resources'][] = ['kind' => $kind, 'name' => $name, 'id' => null];
            AtomicFile::writeJson($this->receipt, $state);
            $label = self::LABEL . '=' . $state['token'];
            $command = $kind === 'container'
                ? ['create', '--name', $name, '--label', $label, ...$arguments]
                : [$kind, 'create', '--label', $label, ...$arguments, $name];
            $id = trim($this->docker->run($command, env: $env)->stdout);
            if ($id === '' || ($kind !== 'volume' && preg_match('/^[a-f0-9]{64}$/D', $id) !== 1)) {
                throw new \RuntimeException('Docker returned no immutable resource identity; recover the saved intention.');
            }
            $state['resources'][array_key_last($state['resources'])]['id'] = $id;
            AtomicFile::writeJson($this->receipt, $state);
            return $id;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        if (!file_exists($this->receipt) && !is_link($this->receipt)) {
            return ['version' => 1, 'token' => bin2hex(random_bytes(16)), 'target' => $this->docker->target->toArray(), 'resources' => []];
        }
        $state = AtomicFile::readJson($this->receipt);
        if (($state['version'] ?? null) !== 1 || !is_string($state['token'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $state['token']) !== 1
            || !is_array($state['target'] ?? null) || !is_array($state['resources'] ?? null) || !array_is_list($state['resources'])) {
            throw new \RuntimeException('Invalid resource receipt; preserve it for recovery.');
        }
        Target::fromArray($state['target']);
        foreach ($state['resources'] as $resource) {
            if (!is_array($resource) || !in_array($resource['kind'] ?? null, array_column(ResourceKind::cases(), 'value'), true)
                || !is_string($resource['name'] ?? null) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/D', $resource['name']) !== 1
                || !array_key_exists('id', $resource) || ($resource['id'] !== null && !is_string($resource['id']))) {
                throw new \RuntimeException('Invalid resource ownership; preserve the receipt.');
            }
        }
        return $state;
    }

    public function cleanup(float $seconds): CleanupReport
    {
        $errors = [];
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
            foreach (array_reverse($state['resources'], true) as $index => $resource) {
                try {
                    self::removeOwnedResource($docker, $resource, $state['token']);
                    unset($state['resources'][$index]);
                } catch (\Throwable $error) {
                    $errors[] = $resource['kind'] . ' ' . $resource['name'] . ': ' . $error->getMessage();
                }
            }
            $state['resources'] = array_values($state['resources']);
            if ($state['resources'] === []) {
                if (!unlink($this->receipt)) {
                    throw new \RuntimeException('Cannot remove completed resource receipt');
                }
            } else {
                AtomicFile::writeJson($this->receipt, $state);
            }
        } catch (\Throwable $error) {
            $errors[] = $error->getMessage();
        } finally {
            try {
                $executor?->close();
            } catch (\Throwable $error) {
                $errors[] = $error->getMessage();
            } finally {
                $lock?->release();
            }
        }
        return new CleanupReport($errors, $this->receipt);
    }

    /** @param array{kind:string,name:string,id:?string} $resource */
    private static function removeOwnedResource(Client $docker, array $resource, string $token): void
    {
        $observed = self::inspect($docker, $resource['kind'], $resource['name']);
        if ($observed === null && $resource['id'] !== null && $resource['id'] !== $resource['name']) {
            $observed = self::inspect($docker, $resource['kind'], $resource['id']);
        }
        if ($observed === null) {
            return;
        }
        $container = $resource['kind'] === ResourceKind::Container->value;
        $labels = $container ? ($observed['Config']['Labels'] ?? []) : ($observed['Labels'] ?? []);
        $observedName = $container ? ltrim($observed['Name'] ?? '', '/') : ($observed['Name'] ?? '');
        $id = $resource['kind'] === ResourceKind::Volume->value ? ($observed['Name'] ?? null) : ($observed['Id'] ?? $observed['ID'] ?? null);
        if ($observedName !== $resource['name'] || ($labels[self::LABEL] ?? null) !== $token || ($resource['id'] !== null && $resource['id'] !== $id)) {
            throw new \RuntimeException('Saved resource ownership does not match; refusing removal');
        }
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException('Cannot resolve owned resource identity');
        }
        $remove = $container ? ['rm', '--force', '--volumes', '--', $id] : [$resource['kind'], 'rm', '--', $id];
        $docker->run($remove);
        if (self::inspect($docker, $resource['kind'], $id) !== null || ($id !== $resource['name'] && self::inspect($docker, $resource['kind'], $resource['name']) !== null)) {
            throw new \RuntimeException('Owned resource remains after removal');
        }
    }

    /** @return array<string,mixed>|null */
    private static function inspect(Client $docker, string $kind, string $name): ?array
    {
        $result = $docker->run([$kind, 'inspect', '--', $name], check: false);
        if ($result->exitCode !== 0) {
            $message = trim($result->stderr);
            $escaped = preg_quote($name, '/');
            $missing = preg_match('/^(?:Error(?: response from daemon)?: )?No such ' . $kind . ': ' . $escaped . '$/Di', $message) === 1
                || ($kind === 'network' && preg_match('/^(?:Error response from daemon: )?network ' . $escaped . ' not found$/Di', $message) === 1)
                || ($kind === 'volume' && preg_match('/^(?:Error response from daemon: )?get ' . $escaped . ': no such volume$/Di', $message) === 1);
            if ($result->exitCode === 1 && $missing) {
                $arguments = [$kind, 'ls'];
                if ($kind === 'container') {
                    $arguments[] = '--all';
                }
                $byId = $kind !== ResourceKind::Volume->value && preg_match('/^[a-f0-9]{64}$/D', $name) === 1;
                if ($byId) {
                    $arguments[] = '--no-trunc';
                }
                // Docker's full JSON formatter requests filesystem sizes and can fail on unrelated container snapshots.
                $format = match ($kind) {
                    'container' => '{"ID":{{json .ID}},"Names":{{json .Names}}}',
                    'network' => '{"ID":{{json .ID}},"Name":{{json .Name}}}',
                    default => '{"Name":{{json .Name}}}',
                };
                $listing = $docker->run([...$arguments, '--format', $format])->stdout;
                foreach (explode("\n", trim($listing)) as $line) {
                    if ($line === '') {
                        continue;
                    }
                    $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    $observed = $entry[$byId ? 'ID' : ($kind === 'container' ? 'Names' : 'Name')] ?? null;
                    if (!is_string($observed)) {
                        throw new \RuntimeException('Invalid resource enumeration');
                    }
                    if (ltrim($observed, '/') === $name) {
                        throw new \RuntimeException('Resource still present in daemon enumeration');
                    }
                }
                return null;
            }
            throw new \RuntimeException('Cannot prove resource absence: ' . $message);
        }
        $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_is_list($data) || count($data) !== 1 || !is_array($data[0])) {
            throw new \RuntimeException('Invalid resource inspection');
        }
        return $data[0];
    }

    public function scoped(callable $body, float $cleanupSeconds): mixed
    {
        $primary = null;
        $result = null;
        try {
            $result = $body();
        } catch (\Throwable $error) {
            $primary = $error;
        }
        $report = $this->cleanup($cleanupSeconds);
        if (!$report->successful()) {
            throw new \RuntimeException(($primary !== null ? $primary->getMessage() . '; ' : '') . $report->message(), $primary !== null ? ($primary->getCode() ?: 1) : (is_int($result) && $result !== 0 ? $result : 1), $primary);
        }
        if ($primary !== null) {
            throw $primary;
        }
        return $result;
    }

    /** Recovery uses the captured target, independent of current invocation configuration.
     * @param array<string,string> $environment
     */
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
}
