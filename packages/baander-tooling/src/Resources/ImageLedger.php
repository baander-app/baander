<?php

declare(strict_types=1);

namespace Baander\Tooling\Resources;

use Baander\Tooling\Docker\Client;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\FileLock;

/** Build tags need a separate intention: Docker build creates images, not create. */
final class ImageLedger
{
    public readonly string $token;
    private readonly FileLock $lock;
    /** @var array<string,?string> */
    private array $images = [];

    public function __construct(public readonly string $receipt, private readonly Client $docker)
    {
        $this->lock = FileLock::acquire($receipt . '.lock');
        if (file_exists($receipt) || is_link($receipt)) {
            $state = AtomicFile::readJson($receipt);
            if (($state['version'] ?? null) !== 1 || ($state['target'] ?? null) != $docker->target->toArray()
                || !is_string($state['token'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $state['token']) !== 1
                || !is_array($state['images'] ?? null)) {
                throw new \RuntimeException('Invalid image ownership receipt; preserve it for recovery.');
            }
            foreach ($state['images'] as $name => $id) {
                if (!is_string($name) || ($id !== null && (!is_string($id) || preg_match('/^sha256:[a-f0-9]{64}$/D', $id) !== 1))) {
                    throw new \RuntimeException('Invalid saved image identity; preserve the receipt.');
                }
            }
            $this->token = $state['token'];
            $this->images = $state['images'];
        } else {
            $this->token = bin2hex(random_bytes(16));
        }
    }

    public function __destruct()
    {
        $this->lock->release();
    }

    public function intend(string $name): void
    {
        if (array_key_exists($name, $this->images)) {
            throw new \RuntimeException('Image intention already exists; recover it first.');
        }
        $this->docker->validateIdentity();
        $this->images[$name] = null;
        $this->save();
    }

    public function observe(string $name): void
    {
        if (!array_key_exists($name, $this->images)) {
            throw new \RuntimeException('Cannot observe an image without an ownership intention.');
        }
        $image = $this->inspect($this->docker, $name);
        if ($image === null) {
            throw new \RuntimeException('Cannot establish built image identity.');
        }
        $this->images[$name] = $this->assertOwnership($name, $image);
        $this->save();
    }

    public function cleanup(Client $docker): CleanupReport
    {
        $errors = [];
        foreach ($this->images as $name => $id) {
            try {
                if ($docker->target->toArray() != $this->docker->target->toArray()) {
                    throw new \RuntimeException('Image cleanup target differs from saved ownership.');
                }
                $docker->validateIdentity();
                $image = $this->inspect($docker, $name);
                if ($image !== null) {
                    // A reused tag never authorizes deletion of the replacement image.
                    $id = $this->assertOwnership($name, $image);
                    $this->removeId($docker, $name, $id);
                } elseif ($id !== null) {
                    $remaining = $this->inspect($docker, $id);
                    if ($remaining !== null) {
                        $this->removeId($docker, $name, $this->assertOwnership($name, $remaining));
                    }
                } else {
                    // An interrupted build can lose its tag before its immutable ID is saved.
                    $listing = $docker->run(['image', 'ls', '--all', '--no-trunc', '--filter', 'label=org.baander.tooling.run=' . $this->token, '--format', '{{json .}}'])->stdout;
                    foreach (explode("\n", trim($listing)) as $line) {
                        if ($line === '') {
                            continue;
                        }
                        $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                        $unresolvedId = $entry['ID'] ?? null;
                        if (!is_string($unresolvedId) || preg_match('/^(?:sha256:)?[a-f0-9]{64}$/D', $unresolvedId) !== 1) {
                            throw new \RuntimeException('Invalid uncertain image enumeration.');
                        }
                        $unresolvedId = str_starts_with($unresolvedId, 'sha256:') ? $unresolvedId : 'sha256:' . $unresolvedId;
                        $unresolved = $this->inspect($docker, $unresolvedId);
                        if ($unresolved !== null) {
                            $this->removeId($docker, $name, $this->assertOwnership($name, $unresolved));
                        }
                    }
                }
                unset($this->images[$name]);
                $this->save();
            } catch (\Throwable $failure) {
                $errors[] = $name . ': ' . $failure->getMessage();
            }
        }
        return new CleanupReport($errors, $this->receipt);
    }

    /** @return array<string,mixed>|null */
    private function inspect(Client $docker, string $name): ?array
    {
        $result = $docker->run(['image', 'inspect', $name], check: false);
        if ($result->exitCode !== 0) {
            if ($result->exitCode !== 1 || trim($result->stderr) !== 'Error response from daemon: No such image: ' . $name) {
                throw new \RuntimeException('Cannot inspect owned image: ' . $result->stderr);
            }
            foreach (explode("\n", trim($docker->run(['image', 'ls', '--all', '--no-trunc', '--format', '{{json .}}'])->stdout)) as $line) {
                if ($line === '') {
                    continue;
                }
                $image = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($image) || !is_string($image['Repository'] ?? null) || !is_string($image['Tag'] ?? null)
                    || !is_string($image['ID'] ?? null) || preg_match('/^(?:sha256:)?[a-f0-9]{64}$/D', $image['ID']) !== 1) {
                    throw new \RuntimeException('Invalid image enumeration.');
                }
                $observedId = str_starts_with($image['ID'], 'sha256:') ? $image['ID'] : 'sha256:' . $image['ID'];
                if ($image['Repository'] . ':' . $image['Tag'] === $name || $observedId === $name) {
                    throw new \RuntimeException('Owned image still present in daemon enumeration.');
                }
            }
            return null;
        }
        $images = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($images) || !array_is_list($images) || count($images) !== 1 || !is_array($images[0])) {
            throw new \RuntimeException('Invalid image inspection.');
        }
        return $images[0];
    }

    private function removeId(Client $docker, string $name, string $id): void
    {
        $docker->run(['image', 'rm', $id]);
        if ($this->inspect($docker, $id) !== null) {
            throw new \RuntimeException('Owned immutable image remains after removal: ' . $name);
        }
    }

    /** @param array<string,mixed> $image */
    private function assertOwnership(string $name, array $image): string
    {
        if (($image['Config']['Labels']['org.baander.tooling.run'] ?? null) !== $this->token
            || !is_string($image['Id'] ?? null) || preg_match('/^sha256:[a-f0-9]{64}$/D', $image['Id']) !== 1
            || ($this->images[$name] !== null && $this->images[$name] !== $image['Id'])) {
            throw new \RuntimeException('Cannot prove image ownership: ' . $name);
        }
        return $image['Id'];
    }

    private function save(): void
    {
        if ($this->images === []) {
            if (file_exists($this->receipt) && !unlink($this->receipt)) {
                throw new \RuntimeException('Cannot remove completed image receipt.');
            }
            return;
        }
        AtomicFile::writeJson($this->receipt, ['version' => 1, 'target' => $this->docker->target->toArray(), 'token' => $this->token, 'images' => $this->images]);
    }
}
