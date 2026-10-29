<?php

declare(strict_types=1);

namespace Baander\Tooling\Resources;

use Baander\Tooling\Docker\Client;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\FileLock;

final class ImageLedger
{
    public readonly string $token;
    private readonly FileLock $lock;
    /** @var array<string,?string> */
    private array $images = [];
    /** @var array<string,string> */
    private array $aliases = [];

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
            $aliases = array_key_exists('aliases', $state) ? $state['aliases'] : [];
            if (!is_array($aliases)) {
                throw new \RuntimeException('Invalid saved build input aliases; preserve the receipt.');
            }
            foreach ($aliases as $name => $id) {
                if (!is_string($name) || preg_match('/^baander-build-input-' . preg_quote($state['token'], '/') . ':[a-z][a-z0-9-]{0,63}$/D', $name) !== 1
                    || !is_string($id) || preg_match('/^sha256:[a-f0-9]{64}$/D', $id) !== 1 || array_key_exists($name, $state['images'])) {
                    throw new \RuntimeException('Invalid or conflicting saved build input ownership; preserve the receipt.');
                }
            }
            $this->token = $state['token'];
            $this->images = $state['images'];
            $this->aliases = $aliases;
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
        if (array_key_exists($name, $this->images) || array_key_exists($name, $this->aliases)) {
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

    /** Give a selected daemon-local image a buildable name without taking ownership of the image. */
    public function alias(string $role, string $id): string
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $role) !== 1 || preg_match('/^sha256:[a-f0-9]{64}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Build inputs require a canonical role and immutable image ID.');
        }
        $name = 'baander-build-input-' . $this->token . ':' . $role;
        if (array_key_exists($name, $this->images) || array_key_exists($name, $this->aliases)) {
            throw new \RuntimeException('Build input intention already exists; recover it first.');
        }
        $this->assertTarget($this->docker);
        $source = $this->inspect($this->docker, $id);
        if (($source['Id'] ?? null) !== $id) {
            throw new \RuntimeException('Selected build input image is absent or changed.');
        }
        if ($this->inspect($this->docker, $name) !== null) {
            throw new \RuntimeException('Build input alias already exists; refusing to replace it.');
        }
        if (!$this->hasPreservingReference($this->docker, $source, $id)) {
            throw new \RuntimeException('Selected build input needs another verified tag or different-repository digest before alias creation.');
        }
        $this->aliases[$name] = $id;
        $this->save();
        $this->docker->run(['image', 'tag', $id, $name]);
        $this->validateAliases($this->docker);
        return $name;
    }

    public function validateAliases(Client $docker): void
    {
        $this->assertTarget($docker);
        foreach ($this->aliases as $name => $id) {
            if (($this->inspect($docker, $name)['Id'] ?? null) !== $id) {
                throw new \RuntimeException('Build input alias is absent or changed: ' . $name);
            }
        }
    }

    private function assertTarget(Client $docker): void
    {
        if ($docker->target->toArray() != $this->docker->target->toArray()) {
            throw new \RuntimeException('Image operation target differs from saved ownership.');
        }
        $docker->validateIdentity();
    }

    /** @param array<string,mixed> $image */
    private function hasPreservingReference(Client $docker, array $image, string $id): bool
    {
        $tags = $image['RepoTags'] ?? [];
        $digests = $image['RepoDigests'] ?? [];
        if (!is_array($tags) || !array_is_list($tags) || !is_array($digests) || !array_is_list($digests)) {
            throw new \RuntimeException('Invalid image reference inventory; shared image preservation cannot be proved.');
        }
        $owned = array_fill_keys(array_map(self::familiarReference(...), array_keys($this->aliases)), true);
        $candidates = [];
        foreach ($tags as $reference) {
            if (!is_string($reference) || $reference === '') {
                throw new \RuntimeException('Invalid image tag inventory.');
            }
            if (!isset($owned[self::familiarReference($reference)])) {
                $candidates[] = $reference;
            }
        }
        foreach ($digests as $reference) {
            if (!is_string($reference) || preg_match('/^(.+)@sha256:[a-f0-9]{64}$/D', $reference, $match) !== 1) {
                throw new \RuntimeException('Invalid image digest inventory.');
            }
            // Docker drops same-repository digests with its last tag; they cannot preserve our shared input.
            if (self::familiarReference($match[1]) !== 'baander-build-input-' . $this->token) {
                $candidates[] = $reference;
            }
        }
        foreach ($candidates as $reference) {
            if (($this->inspect($docker, $reference)['Id'] ?? null) === $id) {
                return true;
            }
        }
        return false;
    }

    private static function familiarReference(string $reference): string
    {
        return preg_replace('~^(?:docker\.io|index\.docker\.io)/library/~', '', $reference) ?? $reference;
    }

    public function cleanup(Client $docker): CleanupReport
    {
        $errors = [];
        foreach ($this->aliases as $name => $id) {
            try {
                $this->assertTarget($docker);
                $image = $this->inspect($docker, $name);
                if ($image !== null) {
                    if (($image['Id'] ?? null) !== $id) {
                        throw new \RuntimeException('Build input alias points to a foreign image; refusing removal.');
                    }
                    if (!$this->hasPreservingReference($docker, $image, $id)) {
                        throw new \RuntimeException('Cannot remove build input alias without another verified reference preserving the shared image.');
                    }
                    $docker->run(['image', 'rm', $name]);
                    if ($this->inspect($docker, $name) !== null) {
                        throw new \RuntimeException('Owned build input alias remains after removal.');
                    }
                }
                if (($this->inspect($docker, $id)['Id'] ?? null) !== $id) {
                    throw new \RuntimeException('Selected shared image is absent after build input alias cleanup.');
                }
                unset($this->aliases[$name]);
                $this->save();
            } catch (\Throwable $failure) {
                $errors[] = $name . ': ' . $failure->getMessage();
            }
        }
        foreach ($this->images as $name => $id) {
            try {
                $this->assertTarget($docker);
                $image = $this->inspect($docker, $name);
                if ($image !== null) {
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
        if ($this->images === [] && $this->aliases === []) {
            if (file_exists($this->receipt) && !unlink($this->receipt)) {
                throw new \RuntimeException('Cannot remove completed image receipt.');
            }
            return;
        }
        AtomicFile::writeJson($this->receipt, ['version' => 1, 'target' => $this->docker->target->toArray(), 'token' => $this->token, 'images' => $this->images, 'aliases' => $this->aliases]);
    }
}
