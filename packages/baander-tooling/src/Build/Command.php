<?php

declare(strict_types=1);

namespace Baander\Tooling\Build;

use Baander\Tooling\Configuration\{ApplicationLayout, ToolingConfiguration};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Docker\{Client, TargetResolver};
use Baander\Tooling\Process\{EngineExecutor, ExecutionContext};

final class Command
{
    /** @param list<string> $arguments
     * @param array<string,string> $environment
     */
    public static function main(array $arguments, string $source, array $environment): int
    {
        $values = ['tag' => [], 'cache-from' => [], 'cache-to' => []];
        $buildx = false;
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if ($argument === '--buildx') {
                $buildx = true;
                continue;
            }
            $key = substr($argument, 2);
            if (!str_starts_with($argument, '--') || !in_array($key, ['source', 'target', 'revision', 'tag', 'cache-from', 'cache-to', 'compose-env'], true)) {
                throw new \InvalidArgumentException('Unknown build argument: ' . $argument);
            }
            $value = array_shift($arguments);
            if ($value === null || $value === '' || str_starts_with($value, '--')) {
                throw new \InvalidArgumentException('Missing value for ' . $argument);
            }
            if (in_array($key, ['tag', 'cache-from', 'cache-to'], true)) {
                $values[$key][] = $value;
            } else {
                $values[$key] = $value;
            }
        }
        $source = $values['source'] ?? $source;
        $target = $values['target'] ?? null;
        $tags = $values['tag'];
        if (!is_string($target) || $tags === []) {
            throw new \InvalidArgumentException('Use php scripts/build-application.php --target TARGET --tag IMAGE [--source DIRECTORY] [--revision REVISION] [--buildx] [--cache-from VALUE] [--cache-to VALUE] [--compose-env FILE].');
        }
        ApplicationLayout::load($source . '/packages/baander-tooling/resources/application-layout.json');
        $configuration = ToolingConfiguration::resolve(environment: $environment);
        $options = new BuildOptions($buildx, array_slice($tags, 1), $values['cache-from'], $values['cache-to']);
        $processes = new EngineExecutor(new ExecutionContext($environment));
        $temporary = sys_get_temp_dir() . '/baander.app-build-' . bin2hex(random_bytes(8));
        $failure = null;
        $logPath = $temporary . '/build.log';
        try {
            $docker = new Client($processes, new TargetResolver($processes, $environment)->resolve($configuration->docker, $temporary), $environment);
            $id = new ApplicationImage($docker, $configuration->images)->build($source, $target, $values['revision'] ?? 'unknown', $tags[0], logPath: $logPath, options: $options);
            if (isset($values['compose-env'])) {
                $image = json_decode($docker->run(['image', 'inspect', $id])->stdout, true, flags: JSON_THROW_ON_ERROR);
                $layout = ApplicationLayout::fromImage($image[0]);
                AtomicFile::write($values['compose-env'], 'BAANDER_APPLICATION_IMAGE=' . $id . "\nAPP_HOME=" . $layout->root . "\n");
            }
            fwrite(STDOUT, $id . "\n");
        } catch (\Throwable $error) {
            $failure = $error;
            throw new \RuntimeException($error->getMessage() . (is_file($logPath) ? ' See ' . $logPath : ''), $error->getCode(), $error);
        } finally {
            $cleanupFailure = null;
            try {
                $processes->close();
                if (is_dir($temporary)) {
                    foreach (glob($temporary . '/*') ?: [] as $file) {
                        if ($failure !== null && $file === $logPath) {
                            continue;
                        }
                        if (!unlink($file)) {
                            throw new \RuntimeException('Cannot remove build transport file: ' . $file);
                        }
                    }
                    if (!is_file($logPath) && !rmdir($temporary)) {
                        throw new \RuntimeException('Cannot remove build workspace: ' . $temporary);
                    }
                }
            } catch (\Throwable $error) {
                $cleanupFailure = $error;
            }
            if ($cleanupFailure !== null) {
                if ($failure === null) {
                    throw $cleanupFailure;
                }
                fwrite(STDERR, 'Build cleanup failed: ' . $cleanupFailure->getMessage() . "\n");
            }
        }
        return 0;
    }
}
