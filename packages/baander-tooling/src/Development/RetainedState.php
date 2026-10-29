<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Configuration\{ApplicationLayout, DockerSettings};
use Baander\Tooling\Docker\{Client, Target, TargetResolver};
use Baander\Tooling\Filesystem\{AtomicFile, FileLock, PrivateDirectory};
use Baander\Tooling\Process\CommandExecutor;

/** A bounded upgrade of retained ownership, before an instance operation takes its lock. */
final readonly class RetainedState
{
    /** @param array<string,string> $environment */
    public function __construct(private StateStore $store, private CommandExecutor $executor, private array $environment) {}

    public function convert(string $identity, bool $activation = true): void
    {
        $observed = $this->store->load($identity);
        if ($observed === null) {
            return;
        }
        self::assertVersion($observed);
        $needed = ($observed['docker']['version'] ?? null) !== 1;
        if ($activation) {
            foreach (['release', 'prepared_release'] as $key) {
                $needed = $needed || (isset($observed[$key]) && ($observed[$key]['version'] ?? null) !== 1);
            }
        }
        $gatewayPath = $this->store->root . '/gateway/state.json';
        $gatewayNeedsConversion = false;
        if (is_file($gatewayPath)) {
            $gatewayRecord = AtomicFile::readJson($gatewayPath);
            self::assertVersion($gatewayRecord);
            $gatewayNeedsConversion = ($gatewayRecord['docker']['version'] ?? null) !== 1;
        }
        if (!$needed && !$gatewayNeedsConversion) {
            return;
        }
        $barrier = new ControllerPayload($this->store->root)->drainReaders();
        try {
            if (!$needed) {
                $this->gateway();
                return;
            }
            $lock = $this->store->lock($identity);
            try {
                $record = $this->store->load($identity);
                if ($record === null) {
                    throw new WorkflowError('Retained instance disappeared during conversion.');
                }
                self::assertVersion($record);
                $directory = $this->store->directory($identity);
                $target = $this->target($record['docker'], $directory);
                $docker = new Client($this->executor, $target, Environment::clean($this->environment));
                $docker->validateIdentity();
                $converted = $record;
                $converted['version'] = 1;
                $converted['docker'] = $target->toArray();
                if ($activation) {
                    foreach (['release', 'prepared_release'] as $key) {
                        if (isset($record[$key])) {
                            $converted[$key] = ($record[$key]['version'] ?? null) === 1
                                ? ReleaseContract::fromArray($record[$key])->toArray()
                                : $this->release($record[$key], $converted, $docker);
                        }
                    }
                }
                $this->backup($directory . '/state.json');
                $this->store->save($identity, $converted);
            } finally {
                $lock->release();
            }
            $this->gateway();
        } finally {
            $barrier->release();
        }
    }

    /** @param array<string,mixed> $record */
    public static function assertVersion(array $record): void
    {
        if (array_key_exists('version', $record) && $record['version'] !== 1) {
            throw new WorkflowError('Unsupported saved instance version; raw status and saved files remain available.');
        }
    }

    /** @param array<string,mixed> $saved */
    private function target(array $saved, string $directory): Target
    {
        if (($saved['version'] ?? null) === 1) {
            return Target::fromArray($saved);
        }
        if (array_key_exists('version', $saved) || !is_string($saved['executable'] ?? null)
            || !is_string($saved['context'] ?? null) || !is_string($saved['daemon_id'] ?? null)) {
            throw new WorkflowError('Unsupported retained Docker ownership; preserve its original record.');
        }
        $target = new TargetResolver($this->executor, $this->environment)->resolve(
            new DockerSettings($saved['executable'], context: $saved['context']),
            $directory . '/transport-' . bin2hex(random_bytes(12)),
        );
        if ($target->daemonId !== $saved['daemon_id']) {
            throw new WorkflowError('The retained Docker context now selects another daemon; the original record is unchanged.');
        }
        return $target;
    }

    /** @param array<string,mixed> $release
     * @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    private function release(array $release, array $instance, Client $docker): array
    {
        if (array_key_exists('version', $release)) {
            throw new WorkflowError('Unsupported retained release version; preserve its original record.');
        }
        $layout = null;
        foreach (ReleaseContract::IMAGE_KEYS as $key) {
            if (!is_string($release[$key] ?? null) || $release[$key] === '') {
                throw new WorkflowError('Retained release has no immutable image identity for ' . $key);
            }
            $information = json_decode($docker->run(['image', 'inspect', $release[$key]])->stdout, true, flags: JSON_THROW_ON_ERROR)[0] ?? null;
            if (!is_array($information) || ($information['Id'] ?? null) !== $release[$key]) {
                throw new WorkflowError('Retained image identity cannot be verified; the original record is unchanged.');
            }
            if (in_array($key, ['app_image', 'worker_image', 'operator_image'], true)) {
                $candidate = $this->historicalLayout($information, $release);
                if ($layout !== null && $layout->toArray() !== $candidate->toArray()) {
                    throw new WorkflowError('Retained application image layouts disagree.');
                }
                $layout = $candidate;
            }
        }
        $release['layout'] = $layout->toArray();
        return ReleaseContract::capture(
            $release,
            $instance,
            ['process' => 120.0, 'cleanup' => 120.0, 'backend' => 3600.0],
            ['release' => 'verified-retained-images-and-source', 'budgets' => 'bounded-conversion-policy'],
            $this->store,
        )->toArray();
    }

    /** @param array<string,mixed> $image
     * @param array<string,mixed> $release
     */
    private function historicalLayout(array $image, array $release): ApplicationLayout
    {
        if (isset($image['Config']['Labels'][ApplicationLayout::IMAGE_LABEL])) {
            return ApplicationLayout::fromImage($image);
        }
        $root = $image['Config']['WorkingDir'] ?? null;
        $source = $release['source'] ?? null;
        if (!is_string($root) || !is_string($source)) {
            throw new WorkflowError('Retained release has no provable historical application layout.');
        }
        $layout = ApplicationLayout::fromArray(['version' => 1, 'root' => $root]);
        $dockerfile = $source . '/Dockerfile';
        PrivateDirectory::assertNoSymlinks($dockerfile);
        $content = is_file($dockerfile) ? file_get_contents($dockerfile) : false;
        if ($content === false || !$this->sourceProvesLayout($content, $root)
            || !is_file($source . '/bin/console')) {
            throw new WorkflowError('Historical source does not prove the retained image application layout.');
        }
        foreach ($image['Config']['Env'] ?? [] as $entry) {
            if (is_string($entry) && str_starts_with($entry, 'APP_HOME=') && substr($entry, 9) !== $root) {
                throw new WorkflowError('Retained image environment disagrees with its historical working directory.');
            }
        }
        return $layout;
    }

    private function sourceProvesLayout(string $content, string $root): bool
    {
        if (preg_match('/^WORKDIR\\s+' . preg_quote($root, '/') . '\\s*$/m', $content) === 1) {
            return true;
        }
        if (preg_match_all('/\\bAPP_HOME(?:[ \\t]*=|[ \\t]+)/', $content) !== 1
            || preg_match('/^ARG[ \\t]+APP_HOME\\b/m', $content) === 1
            || preg_match('/^ENV[ \\t]+APP_HOME=' . preg_quote($root, '/') . '(?:[ \\t]+\\\\)?[ \\t]*$/m', $content, $declaration, PREG_OFFSET_CAPTURE) !== 1
            || preg_match('/^WORKDIR[ \\t]+\\$\\{APP_HOME\\}[ \\t]*$/m', $content, $workdir, PREG_OFFSET_CAPTURE) !== 1
            || $declaration[0][1] >= $workdir[0][1]) {
            return false;
        }
        $instructions = substr($content, $declaration[0][1], $workdir[0][1] - $declaration[0][1]);
        return preg_match('/^FROM[ \\t]/mi', $instructions) !== 1;
    }

    private function gateway(): void
    {
        $directory = $this->store->root . '/gateway';
        $path = $directory . '/state.json';
        if (!is_file($path)) {
            return;
        }
        $lock = FileLock::acquire($directory . '/operation.lock', false);
        try {
            $record = AtomicFile::readJson($path);
            self::assertVersion($record);
            if (($record['docker']['version'] ?? null) === 1) {
                return;
            }
            $target = $this->target($record['docker'], $directory);
            $converted = [...$record, 'version' => 1, 'docker' => $target->toArray()];
            $this->backup($path);
            AtomicFile::writeJson($path, $converted);
        } finally {
            $lock->release();
        }
    }

    private function backup(string $path): void
    {
        PrivateDirectory::assertNoSymlinks($path);
        $original = file_get_contents($path);
        if ($original === false) {
            throw new WorkflowError('Cannot retain original state before conversion.');
        }
        AtomicFile::write($path . '.pre-contract-' . bin2hex(random_bytes(8)), $original);
    }
}
