<?php

declare(strict_types=1);

namespace Baander\Registry\Qualification;

/** Standalone relay composition supplies its bounded Docker command executor. */
final class DockerOwnership
{
    /** @var resource */
    private $lock;
    private string $token;
    private ?float $cleanupDeadline = null;
    /** @var array<int,array{kind:string,name:string,id:?string}> */
    private array $resources = [];

    /**
     * @param array<string,mixed> $target Captured tooling Target transport contract.
     * @param \Closure(list<string>,?string,float):array{code:int,stdout:string,stderr:string} $execute
     */
    public function __construct(public readonly string $receipt, private readonly array $target, private readonly \Closure $execute)
    {
        Runtime::requireThat(str_starts_with($receipt, '/') && realpath(dirname($receipt)) === dirname($receipt), 'Ownership receipt needs an existing canonical directory.');
        Runtime::requireThat((fileperms(dirname($receipt)) & 0077) === 0 && !is_link($receipt) && !is_link($receipt . '.lock'), 'Ownership receipt directory must be private and without symlinks.');
        self::validateTarget($target);
        $this->lock = fopen($receipt . '.lock', 'c+b');
        Runtime::requireThat(is_resource($this->lock) && chmod($receipt . '.lock', 0600) && flock($this->lock, LOCK_EX | LOCK_NB), 'Cannot acquire relay resource ownership.');
        if (file_exists($receipt)) {
            $state = self::read($receipt);
            Runtime::requireThat(($state['version'] ?? null) === 1 && ($state['target'] ?? null) === $target
                && is_string($state['token'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $state['token']) === 1
                && is_array($state['resources'] ?? null) && array_is_list($state['resources']), 'Invalid relay ownership receipt; preserve it for recovery.');
            foreach ($state['resources'] as $resource) {
                Runtime::requireThat(is_array($resource) && in_array($resource['kind'] ?? null, ['container', 'volume', 'network'], true)
                    && is_string($resource['name'] ?? null) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/D', $resource['name']) === 1
                    && array_key_exists('id', $resource) && ($resource['id'] === null || is_string($resource['id'])), 'Invalid saved relay resource identity.');
            }
            $this->token = $state['token'];
            $this->resources = $state['resources'];
        } else {
            $this->token = bin2hex(random_bytes(16));
        }
        $this->identity(30);
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
    }

    /** @return array<string,mixed> */
    public static function read(string $path): array
    {
        Runtime::requireThat(is_file($path) && !is_link($path) && (fileperms($path) & 0077) === 0, 'Saved Docker ownership must be a private regular file.');
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        Runtime::requireThat(is_array($data), 'Invalid saved Docker ownership JSON.');
        return $data;
    }

    /** @param array<string,mixed> $target */
    private static function validateTarget(array $target): void
    {
        Runtime::requireThat(array_diff(array_keys($target), ['version', 'executable', 'endpoint', 'daemonId', 'originalContext', 'snapshotDirectory', 'transportArguments', 'files']) === [], 'Unknown captured relay Docker target field.');
        Runtime::requireThat(($target['version'] ?? null) === 1 && is_string($target['executable'] ?? null)
            && str_starts_with($target['executable'], '/') && is_file($target['executable']) && is_executable($target['executable'])
            && is_string($target['endpoint'] ?? null) && preg_match('~^(?:unix|tcp|ssh|npipe)://[^\x00-\x20]+$~D', $target['endpoint']) === 1
            && is_string($target['daemonId'] ?? null) && $target['daemonId'] !== ''
            && is_array($target['transportArguments'] ?? null) && array_is_list($target['transportArguments'])
            && is_array($target['files'] ?? null), 'Invalid captured relay Docker target.');
        $snapshot = $target['snapshotDirectory'] ?? null;
        Runtime::requireThat($snapshot === null || (is_string($snapshot) && str_starts_with($snapshot, '/')
            && realpath($snapshot) === $snapshot && is_dir($snapshot) && (fileperms($snapshot) & 0077) === 0), 'Captured Docker snapshot directory is missing or unsafe.');
        foreach ($target['files'] as $path => $digest) {
            Runtime::requireThat(is_string($path) && is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1
                && $snapshot !== null && dirname($path) === $snapshot, 'Invalid captured Docker TLS file inventory.');
        }
        $arguments = $target['transportArguments'];
        if ($arguments !== []) {
            Runtime::requireThat(count($arguments) === 7 && in_array($arguments[0], ['--tls', '--tlsverify'], true), 'Invalid captured Docker TLS arguments.');
            foreach ([1 => '--tlscacert', 3 => '--tlscert', 5 => '--tlskey'] as $offset => $flag) {
                Runtime::requireThat($arguments[$offset] === $flag && is_string($arguments[$offset + 1])
                    && ($arguments[$offset + 1] === '' || isset($target['files'][$arguments[$offset + 1]])), 'Docker TLS arguments must use captured connection files.');
            }
        } else {
            Runtime::requireThat($target['files'] === [], 'Captured Docker TLS files have no transport arguments.');
        }
    }

    /** @param list<string> $arguments
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function raw(array $arguments, ?string $input, float $seconds): array
    {
        if ($this->cleanupDeadline !== null) {
            $seconds = min($seconds, $this->cleanupDeadline - microtime(true));
        }
        Runtime::requireThat(is_finite($seconds) && $seconds > 0, 'Relay Docker execution budget expired.');
        foreach ($this->target['files'] as $path => $digest) {
            Runtime::requireThat(is_string($path) && is_string($digest) && is_file($path) && !is_link($path)
                && (fileperms($path) & 0077) === 0 && hash_file('sha256', $path) === $digest, 'Captured Docker connection material changed.');
        }
        try {
            return ($this->execute)([$this->target['executable'], '--host', $this->target['endpoint'], ...$this->target['transportArguments'], ...$arguments], $input, $seconds);
        } catch (\RuntimeException $failure) {
            if ($failure->getMessage() === 'Child wait deadline exceeded.') {
                throw new \RuntimeException('Relay Docker command timed out.', 124, $failure);
            }
            throw $failure;
        }
    }

    private function identity(float $seconds): void
    {
        $result = $this->raw(['info', '--format', '{{json .}}'], null, $seconds);
        $information = $result['code'] === 0 ? json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR) : [];
        Runtime::requireThat(($information['ID'] ?? null) === $this->target['daemonId'], 'Docker daemon differs from saved relay ownership.');
    }

    /** @param list<string> $arguments
     * @return array{code:int,stdout:string,stderr:string}
     */
    public function run(array $arguments, ?string $input = null, float $seconds = 180): array
    {
        $container = in_array($arguments[0] ?? null, ['create', 'run'], true);
        $kind = $container ? 'container' : (in_array($arguments[0] ?? null, ['volume', 'network'], true) ? $arguments[0] : null);
        $creating = $container || ($kind !== null && ($arguments[1] ?? '') === 'create');
        $removing = ($arguments[0] ?? '') === 'rm' || ($kind !== null && ($arguments[1] ?? '') === 'rm');
        if ($removing) {
            return $this->remove($kind ?? 'container', $arguments[array_key_last($arguments)], $arguments, $seconds);
        }
        if (!$creating || $kind === null) {
            return $this->raw($arguments, $input, $seconds);
        }
        if ($container) {
            $position = array_search('--name', $arguments, true);
            $name = $position === false ? 'baander-registry-transient-' . bin2hex(random_bytes(12)) : ($arguments[$position + 1] ?? '');
            if ($position === false) {
                array_splice($arguments, 1, 0, ['--name', $name]);
            }
            $offset = 1;
        } else {
            $name = $arguments[array_key_last($arguments)];
            $offset = 2;
        }
        Runtime::requireThat(preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/D', $name) === 1, 'Relay Docker resource needs a canonical name.');
        Runtime::requireThat($this->index($kind, $name) === null, 'Relay resource intention already exists; recover it first.');
        $this->identity(min(30, $seconds));
        $this->resources[] = ['kind' => $kind, 'name' => $name, 'id' => null];
        $this->save();
        array_splice($arguments, $offset, 0, ['--label', 'org.baander.tooling.run=' . $this->token]);
        $result = $this->raw($arguments, $input, $seconds);
        try {
            $observed = $this->inspect($kind, $name, min(30, $seconds));
            $index = $this->index($kind, $name);
            if ($observed === null) {
                unset($this->resources[$index]);
            } else {
                $this->resources[$index]['id'] = $this->owned($this->resources[$index], $observed);
            }
            $this->save();
        } catch (\Throwable $failure) {
            throw new \RuntimeException('Cannot resolve created relay resource: ' . $failure->getMessage(), $result['code'] ?: 1, $failure);
        }
        return $result;
    }

    private function index(string $kind, string $name): ?int
    {
        foreach ($this->resources as $index => $resource) {
            if ($resource['kind'] === $kind && $resource['name'] === $name) {
                return $index;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function inspect(string $kind, string $name, float $seconds): ?array
    {
        $result = $this->raw([$kind, 'inspect', '--', $name], null, $seconds);
        if ($result['code'] !== 0) {
            $escaped = preg_quote($name, '/');
            $missing = preg_match('/^(?:Error(?: response from daemon)?: )?No such ' . $kind . ': ' . $escaped . '$/Di', trim($result['stderr'])) === 1
                || ($kind === 'network' && preg_match('/^(?:Error response from daemon: )?network ' . $escaped . ' not found$/Di', trim($result['stderr'])) === 1)
                || ($kind === 'volume' && preg_match('/^(?:Error response from daemon: )?get ' . $escaped . ': no such volume$/Di', trim($result['stderr'])) === 1);
            Runtime::requireThat($result['code'] === 1 && $missing, 'Cannot prove relay resource absence: ' . $result['stderr']);
            $listing = $this->raw([$kind, 'ls', ...($kind === 'container' ? ['--all', '--no-trunc'] : ($kind === 'network' ? ['--no-trunc'] : [])), '--format', '{{json .}}'], null, $seconds);
            Runtime::requireThat($listing['code'] === 0, 'Cannot enumerate relay resources to prove absence.');
            foreach (explode("\n", trim($listing['stdout'])) as $line) {
                if ($line === '') {
                    continue;
                }
                $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $observed = $entry[$kind === 'container' ? 'Names' : 'Name'] ?? null;
                Runtime::requireThat(is_string($observed) && ltrim($observed, '/') !== $name && ($entry['ID'] ?? null) !== $name, 'Relay resource remains in daemon enumeration.');
            }
            return null;
        }
        $entries = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        Runtime::requireThat(is_array($entries) && array_is_list($entries) && count($entries) === 1 && is_array($entries[0]), 'Invalid relay resource inspection.');
        return $entries[0];
    }

    /** @param array{kind:string,name:string,id:?string} $resource
     * @param array<string,mixed> $observed
     */
    private function owned(array $resource, array $observed): string
    {
        $container = $resource['kind'] === 'container';
        $name = $container ? ltrim($observed['Name'] ?? '', '/') : ($observed['Name'] ?? '');
        $labels = $container ? ($observed['Config']['Labels'] ?? []) : ($observed['Labels'] ?? []);
        $id = $resource['kind'] === 'volume' ? ($observed['Name'] ?? null) : ($observed['Id'] ?? $observed['ID'] ?? null);
        Runtime::requireThat($name === $resource['name'] && ($labels['org.baander.tooling.run'] ?? null) === $this->token
            && is_string($id) && $id !== '' && ($resource['kind'] === 'volume' || preg_match('/^[a-f0-9]{64}$/D', $id) === 1)
            && ($resource['id'] === null || $resource['id'] === $id), 'Cannot prove relay resource ownership: ' . $resource['name']);
        return $id;
    }

    /** @param list<string> $arguments
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function remove(string $kind, string $name, array $arguments, float $seconds): array
    {
        $index = $this->index($kind, $name);
        Runtime::requireThat($index !== null, 'Refusing removal without saved relay ownership: ' . $name);
        $this->identity(min(30, $seconds));
        $observed = $this->inspect($kind, $name, $seconds);
        if ($observed === null && $kind !== 'volume' && $this->resources[$index]['id'] !== null) {
            $observed = $this->inspect($kind, $this->resources[$index]['id'], $seconds);
        }
        if ($observed === null) {
            $result = ['code' => 0, 'stdout' => '', 'stderr' => ''];
        } else {
            $arguments[array_key_last($arguments)] = $this->owned($this->resources[$index], $observed);
            $result = $this->raw($arguments, null, $seconds);
            if ($result['code'] !== 0) {
                return $result;
            }
            Runtime::requireThat($this->inspect($kind, $name, $seconds) === null, 'Owned relay resource remains after removal.');
        }
        unset($this->resources[$index]);
        $this->save();
        return $result;
    }

    /** @return list<string> */
    public function cleanup(float $seconds): array
    {
        Runtime::requireThat(is_finite($seconds) && $seconds > 0, 'Relay cleanup needs a positive finite budget.');
        $deadline = microtime(true) + $seconds;
        $this->cleanupDeadline = $deadline;
        $errors = [];
        try {
            foreach (array_reverse($this->resources) as $resource) {
                try {
                    $remaining = $deadline - microtime(true);
                    Runtime::requireThat($remaining > 0, 'Relay cleanup budget expired.');
                    $arguments = $resource['kind'] === 'container' ? ['rm', '-f', '-v', '--', $resource['name']] : [$resource['kind'], 'rm', '--', $resource['name']];
                    $result = $this->remove($resource['kind'], $resource['name'], $arguments, $remaining);
                    Runtime::requireThat($result['code'] === 0, 'Docker removal failed: ' . $result['stderr']);
                } catch (\Throwable $failure) {
                    $errors[] = $resource['kind'] . ' ' . $resource['name'] . ': ' . $failure->getMessage();
                }
            }
        } finally {
            $this->cleanupDeadline = null;
        }
        return $errors;
    }

    private function save(): void
    {
        $this->resources = array_values($this->resources);
        if ($this->resources === []) {
            if (file_exists($this->receipt)) {
                Runtime::requireThat(unlink($this->receipt), 'Cannot remove completed relay ownership receipt.');
            }
            return;
        }
        $temporary = $this->receipt . '.' . bin2hex(random_bytes(8));
        $stream = fopen($temporary, 'xb');
        Runtime::requireThat(is_resource($stream), 'Cannot write relay resource intention.');
        try {
            Runtime::requireThat(chmod($temporary, 0600), 'Cannot protect relay ownership receipt.');
            $json = json_encode(['version' => 1, 'target' => $this->target, 'token' => $this->token, 'resources' => $this->resources], JSON_THROW_ON_ERROR);
            Runtime::requireThat(fwrite($stream, $json) === strlen($json) && fflush($stream) && fsync($stream), 'Cannot synchronize relay ownership receipt.');
        } finally {
            fclose($stream);
        }
        Runtime::requireThat(rename($temporary, $this->receipt), 'Cannot publish relay ownership receipt.');
        $directory = fopen(dirname($this->receipt), 'r');
        try {
            Runtime::requireThat(is_resource($directory) && fsync($directory), 'Cannot synchronize relay ownership directory.');
        } finally {
            if (is_resource($directory)) {
                fclose($directory);
            }
        }
    }
}
