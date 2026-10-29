<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

class Libraries
{
    public function __construct(private readonly Runner $runner) {}

    /** @param array<string,mixed> $instance */
    public function ensure(array $instance): void
    {
        $definitions = $instance['libraries'] ?? [];
        if ($definitions === []) {
            return;
        }
        $docker = new Docker($this->runner, $instance['docker']);
        $command = ['exec', '-T', 'app', 'php', 'bin/console'];
        try {
            $catalog = $docker->compose($instance, [...$command, 'app:library:list', '--json'])->stdout
                |> (static fn(string $output): mixed => json_decode($output, flags: JSON_THROW_ON_ERROR));
            if (!is_array($catalog)) {
                throw new WorkflowError('Development library list must be a JSON array.');
            }
            $existing = [];
            foreach ($catalog as $entry) {
                if (!$entry instanceof \stdClass) {
                    throw new WorkflowError('Development library list contains an invalid library.');
                }
                $library = get_object_vars($entry);
                foreach (['slug', 'name', 'type', 'path', 'filesystemType'] as $field) {
                    if (!isset($library[$field]) || !is_string($library[$field])) {
                        throw new WorkflowError('Development library list contains an invalid library.');
                    }
                }
                $existing[$library['slug']] = $library;
            }
        } catch (WorkflowError|\JsonException $error) {
            throw new WorkflowError('Cannot list development libraries: ' . $error->getMessage(), previous: $error);
        }
        foreach ($definitions as $definition) {
            $library = $existing[$definition['slug']] ?? null;
            if ($library === null) {
                continue;
            }
            foreach (['name' => 'name', 'type' => 'type', 'path' => 'target'] as $field => $configured) {
                if ($library[$field] !== $definition[$configured]) {
                    throw new WorkflowError(sprintf(
                        'Development library "%s" has conflicting %s; reconcile its configuration or catalog entry before starting.',
                        $definition['slug'],
                        $field,
                    ));
                }
            }
            if ($library['filesystemType'] !== 'local') {
                throw new WorkflowError(sprintf('Development library "%s" must use the local filesystem.', $definition['slug']));
            }
        }
        foreach ($definitions as $definition) {
            if (isset($existing[$definition['slug']])) {
                continue;
            }
            try {
                $docker->compose($instance, [
                    ...$command,
                    'app:library:create',
                    '--slug',
                    $definition['slug'],
                    '--json',
                    '--',
                    $definition['name'],
                    $definition['target'],
                    $definition['type'],
                ]);
            } catch (WorkflowError $error) {
                throw new WorkflowError(sprintf(
                    'Cannot create development library "%s": %s',
                    $definition['slug'],
                    $error->getMessage(),
                ), previous: $error);
            }
        }
    }
}
