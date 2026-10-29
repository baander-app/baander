<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Configuration\{ApplicationLayout, ToolingConfiguration};
use Baander\Tooling\Docker\Target;
use Baander\Tooling\Filesystem\PrivateDirectory;

/** The complete immutable input to a prepared activation. Secrets remain in env.json. */
final readonly class ReleaseContract
{
    public const int VERSION = 1;
    public const array IMAGE_KEYS = ['app_image', 'tool_image', 'worker_image', 'operator_image', 'postgres_image', 'nginx_image', 'redis_image'];

    /** @param array<string,mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new WorkflowError('Unsupported prepared release contract; preserve saved state for conversion.');
        }
        foreach (['revision', 'source'] as $field) {
            if (!is_string($data[$field] ?? null) || $data[$field] === '') {
                throw new WorkflowError('Invalid prepared release ' . $field . '.');
            }
        }
        foreach (['layout', 'target', 'images', 'effective', 'inputs', 'sourceIdentity'] as $field) {
            if (!is_array($data[$field] ?? null)) {
                throw new WorkflowError('Invalid prepared release ' . $field . '.');
            }
        }
        ApplicationLayout::fromArray($data['layout']);
        $target = Target::fromArray($data['target']);
        foreach (self::IMAGE_KEYS as $key) {
            $image = $data['images'][$key] ?? null;
            if (!is_array($image) || !is_string($image['reference'] ?? null) || !is_string($image['resolvedId'] ?? null)
                || $image['resolvedId'] === '' || ($image['daemonId'] ?? null) !== $target->daemonId || !is_array($image['capabilities'] ?? null)) {
                throw new WorkflowError('Invalid prepared release image identity: ' . $key);
            }

        }
        foreach ($data['inputs'] as $path => $digest) {
            if (!is_string($path) || !str_starts_with($path, '/') || !is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new WorkflowError('Invalid prepared release input identity.');
            }
        }
        foreach (['media', 'libraries', 'budgets', 'provenance', 'state_mounts', 'volumes'] as $field) {
            if (!is_array($data['effective'][$field] ?? null)) {
                throw new WorkflowError('Invalid saved activation configuration.');
            }
        }
        if (!str_starts_with($data['source'], '/') || $data['sourceIdentity'] === []
            || !is_string($data['sourceDigest'] ?? null)
            || !hash_equals(hash('sha256', json_encode($data['sourceIdentity'], JSON_THROW_ON_ERROR)), $data['sourceDigest'])
            || !is_string($data['environmentFile'] ?? null) || !isset($data['inputs'][$data['environmentFile']])) {
            throw new WorkflowError('Prepared release has an incomplete materialized-source or private-input inventory.');
        }
        foreach ($data['sourceIdentity'] as $relative => $identity) {
            if (!is_string($relative) || $relative === '' || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)
                || !is_array($identity) || !in_array($identity['type'] ?? null, ['file', 'link'], true)
                || !is_int($identity['mode'] ?? null) || !is_string($identity['digest'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $identity['digest']) !== 1) {
                throw new WorkflowError('Invalid materialized source inventory.');
            }
        }
        new \Baander\Tooling\Configuration\WorkflowBudgets($data['effective']['budgets']);
        return new self($data);
    }

    /** @param array<string,mixed> $release
     * @param array<string,mixed> $instance
     */
    public static function prepared(array $release, array $instance, ToolingConfiguration $configuration, StateStore $store): self
    {
        return self::capture($release, $instance, $configuration->budgets->toArray(), $configuration->provenance, $store);
    }

    /** @param array<string,mixed> $release
     * @param array<string,mixed> $instance
     * @param array<string,float> $budgets
     * @param array<string,string> $provenance
     */
    public static function capture(array $release, array $instance, array $budgets, array $provenance, StateStore $store): self
    {
        $release['version'] = self::VERSION;
        $release['target'] = $instance['docker'];
        $release['images'] = [];
        foreach (self::IMAGE_KEYS as $key) {
            $selection = $release['image_selections'][$key] ?? null;
            $retained = $selection['retained'] ?? false;
            $release['images'][$key] = [
                'reference' => $retained ? ($release['image_pins'][$key] ?? $release[$key]) : ($selection['reference'] ?? $release['image_pins'][$key] ?? $release[$key]),
                'resolvedId' => $release[$key],
                'capabilities' => $retained ? ($instance['release']['images'][$key]['capabilities'] ?? []) : ($selection['capabilities'] ?? []),
                'daemonId' => Target::fromArray($instance['docker'])->daemonId,
            ];
        }
        foreach (self::IMAGE_KEYS as $key) {
            unset($release[$key]);
        }
        $release['effective'] = [
            'media' => $instance['media'] ?? [],
            'libraries' => $instance['libraries'] ?? [],
            'budgets' => $budgets,
            'provenance' => $provenance,
            'state_mounts' => $instance['state_mounts'] ?? [],
            'volumes' => $instance['volumes'] ?? [],
            'postgres_image' => $instance['postgres_image'] ?? $release['images']['postgres_image']['resolvedId'],
            'redis_image' => $instance['redis_image'] ?? $release['images']['redis_image']['resolvedId'],
        ];
        $release['environmentFile'] = $store->directory($instance['id']) . '/env.json';
        $release['sourceIdentity'] = self::sourceIdentity($release['source']);
        $release['sourceDigest'] = hash('sha256', json_encode($release['sourceIdentity'], JSON_THROW_ON_ERROR));
        $release['inputs'] = self::inputIdentities($release['source'], $store->directory($instance['id']) . '/env.json');
        return self::fromArray($release);
    }

    /** @return array<string,array{type:string,digest:string,mode:int}> */
    public static function sourceIdentity(string $source): array
    {
        PrivateDirectory::assertNoSymlinks($source);
        if (!is_dir($source)) {
            throw new WorkflowError('Materialized release source is unavailable.');
        }
        $result = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen(rtrim($source, '/')) + 1);
            $link = is_link($path);
            $digest = $link ? readlink($path) : hash_file('sha256', $path);
            $mode = lstat($path);
            if ($digest === false || $mode === false || (!$link && !is_file($path))) {
                throw new WorkflowError('Cannot identify materialized source entry: ' . $relative);
            }
            $result[$relative] = ['digest' => $link ? hash('sha256', $digest) : $digest, 'mode' => $mode['mode'] & 0777, 'type' => $link ? 'link' : 'file'];
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @return array<string,string> */
    public static function inputIdentities(string $source, string $environment): array
    {
        $paths = [$environment, $source . '/Dockerfile'];
        foreach (['docker/dev', 'packages/baander-tooling/resources'] as $directory) {
            $directory = $source . '/' . $directory;
            if (is_dir($directory)) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $paths[] = $file->getPathname();
                    }
                }
            }
        }
        sort($paths, SORT_STRING);
        $result = [];
        foreach ($paths as $path) {
            PrivateDirectory::assertNoSymlinks($path);
            $result[$path] = is_file($path) ? hash_file('sha256', $path) : false;
            if ($result[$path] === false) {
                throw new WorkflowError('Prepared release input is unavailable: ' . $path);
            }
        }
        return $result;
    }

    /** Validate saved inputs before any resumed activation mutates resources.
     * @param array<string,mixed> $instance
     */
    public function validateInputs(array $instance): void
    {
        if (!Connections::sameTarget($instance['docker'], $this->data['target'])) {
            throw new WorkflowError('Prepared release belongs to another Docker target.');
        }
        if (($instance['generated_inputs']['release'] ?? null) === $this->data['sourceDigest']) {
            self::validateGeneratedInputs($instance);
        }
        if (self::sourceIdentity($this->data['source']) !== $this->data['sourceIdentity']) {
            throw new WorkflowError('Materialized release source changed; preserve and restore the recorded release inputs.');
        }
        foreach ($this->data['inputs'] as $path => $digest) {
            PrivateDirectory::assertNoSymlinks($path);
            $actual = is_file($path) ? hash_file('sha256', $path) : false;
            if ($actual === false || !hash_equals($digest, $actual)) {
                throw new WorkflowError('Prepared release inputs changed; preserve the saved release and recover its recorded inputs.');
            }
        }
    }

    /** Generated artifacts have a separate publication boundary because routing changes them.
     * @param array<string,mixed> $instance
     */
    public static function validateGeneratedInputs(array $instance): void
    {
        if (!isset($instance['generated_inputs'])) {
            return;
        }
        $identity = $instance['generated_inputs'];
        if (!is_array($identity) || !is_string($identity['release'] ?? null) || !is_array($identity['files'] ?? null) || $identity['files'] === []) {
            throw new WorkflowError('Invalid saved generated-input identity; preserve the instance files.');
        }
        foreach ($identity['files'] as $path => $digest) {
            if (!is_string($path) || !str_starts_with($path, '/') || !is_string($digest)) {
                throw new WorkflowError('Invalid saved generated-input file identity.');
            }
            PrivateDirectory::assertNoSymlinks($path);
            $actual = is_file($path) ? hash_file('sha256', $path) : false;
            if ($actual === false || !hash_equals($digest, $actual)) {
                throw new WorkflowError('Saved Compose or frontend configuration changed; restore its recorded content before using retained ownership.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
