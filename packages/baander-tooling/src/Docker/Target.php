<?php

declare(strict_types=1);

namespace Baander\Tooling\Docker;

use Baander\Tooling\Configuration\DockerSettings;
use Baander\Tooling\Filesystem\PrivateDirectory;

final readonly class Target
{
    /** @param list<string> $transportArguments
     * @param array<string,string> $files Absolute snapshot paths and SHA-256 digests.
     */
    public function __construct(
        public string $executable,
        public string $endpoint,
        public string $daemonId,
        public ?string $originalContext,
        public ?string $snapshotDirectory,
        public array $transportArguments = [],
        public array $files = [],
    ) {
        new DockerSettings($executable, host: $endpoint);
        if (!str_starts_with($executable, '/') || ($snapshotDirectory !== null && !str_starts_with($snapshotDirectory, '/'))) {
            throw new \InvalidArgumentException('Docker target executable and snapshot paths must be absolute.');
        }
        if ($daemonId === '' || preg_match('/[\x00-\x1f]/', $daemonId) === 1) {
            throw new \InvalidArgumentException('Docker daemon identity must be nonempty.');
        }
        foreach ($files as $path => $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || $snapshotDirectory === null || dirname($path) !== $snapshotDirectory) {
                throw new \InvalidArgumentException('Invalid Docker transport snapshot inventory.');
            }
        }
        if ($transportArguments !== []) {
            if (count($transportArguments) !== 7 || !in_array($transportArguments[0], ['--tls', '--tlsverify'], true)) {
                throw new \InvalidArgumentException('Invalid Docker TLS transport arguments.');
            }
            foreach ([1 => '--tlscacert', 3 => '--tlscert', 5 => '--tlskey'] as $offset => $flag) {
                if ($transportArguments[$offset] !== $flag || ($transportArguments[$offset + 1] !== '' && !isset($files[$transportArguments[$offset + 1]]))) {
                    throw new \InvalidArgumentException('Docker TLS arguments must name captured connection material.');
                }
            }
        } elseif ($files !== []) {
            throw new \InvalidArgumentException('Docker TLS snapshot has no connection arguments.');
        }
    }

    public function assertSnapshot(): void
    {
        if ($this->snapshotDirectory !== null) {
            if (!is_dir($this->snapshotDirectory)) {
                throw new \RuntimeException('Docker transport snapshot is missing.');
            }
            PrivateDirectory::ensure($this->snapshotDirectory);
            if ((fileperms($this->snapshotDirectory) & 0077) !== 0) {
                throw new \RuntimeException('Docker transport snapshot is not private.');
            }
        }
        foreach ($this->files as $path => $digest) {
            clearstatcache(true, $path);
            $actual = is_file($path) && is_readable($path) ? hash_file('sha256', $path) : false;
            if (is_link($path) || $actual === false || (fileperms($path) & 0077) !== 0 || !hash_equals($digest, $actual)) {
                throw new \RuntimeException('Docker transport snapshot is missing, changed or not private.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['version' => 1, 'executable' => $this->executable, 'endpoint' => $this->endpoint, 'daemonId' => $this->daemonId, 'originalContext' => $this->originalContext, 'snapshotDirectory' => $this->snapshotDirectory, 'transportArguments' => $this->transportArguments, 'files' => $this->files];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['version'] ?? null) !== 1 || array_diff(array_keys($data), ['version', 'executable', 'endpoint', 'daemonId', 'originalContext', 'snapshotDirectory', 'transportArguments', 'files']) !== []) {
            throw new \InvalidArgumentException('Invalid saved Docker target contract.');
        }
        foreach (['executable', 'endpoint', 'daemonId'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new \InvalidArgumentException('Invalid saved Docker target field: ' . $key);
            }
        }
        foreach (['originalContext', 'snapshotDirectory'] as $key) {
            if (!array_key_exists($key, $data) || ($data[$key] !== null && !is_string($data[$key]))) {
                throw new \InvalidArgumentException('Invalid saved Docker target field: ' . $key);
            }
        }
        if (!isset($data['transportArguments'], $data['files']) || !is_array($data['transportArguments']) || !array_is_list($data['transportArguments']) || !is_array($data['files'])) {
            throw new \InvalidArgumentException('Invalid saved Docker transport.');
        }
        foreach ($data['transportArguments'] as $argument) {
            if (!is_string($argument)) {
                throw new \InvalidArgumentException('Invalid saved Docker transport argument.');
            }
        }
        foreach ($data['files'] as $path => $hash) {
            if (!is_string($path) || !is_string($hash)) {
                throw new \InvalidArgumentException('Invalid saved Docker transport snapshot inventory.');
            }
        }
        return new self($data['executable'], $data['endpoint'], $data['daemonId'], $data['originalContext'], $data['snapshotDirectory'], $data['transportArguments'], $data['files']);
    }
}
