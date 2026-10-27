<?php

declare(strict_types=1);

namespace Baander\Tooling\Inventory;

use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Filesystem\AtomicFile;

final class Collector
{
    /** @return array<string, mixed> */
    public static function collect(string $image, string $target, string $revision, string $output, string $session): array
    {
        if ($image === '' || $revision === '' || $output === '' || !in_array($target, ['production', 'worker'], true)) {
            throw new InventoryError('Image, production|worker target, revision and output are required', 2);
        }
        ApplicationInventory::directory($output);
        $log = $output . '/collection.log';
        file_put_contents($log, '');
        $token = bin2hex(random_bytes(8));
        $work = rtrim(sys_get_temp_dir(), '/') . '/baander-application-inventory.' . $token;
        $container = 'baander-application-inventory-' . $token;
        $state = ['container' => $container, 'work' => $work, 'output' => $output, 'create_started' => false];
        AtomicFile::writeJson($session . '/state.json', $state);
        $runner = new ProcessRunner();
        try {
            ApplicationInventory::directory($work);
            $inspect = self::run($runner, ['docker', 'image', 'inspect', '--', $image], $log);
            file_put_contents($output . '/image-inspect.json', $inspect);
            $identity = ApplicationInventory::imageIdentity(Json::read($output . '/image-inspect.json'), $target);
            ApplicationInventory::checkIdentity($identity, $revision);
            $state['create_started'] = true;
            AtomicFile::writeJson($session . '/state.json', $state);
            $containerId = self::run(
                $runner,
                ['docker', 'create', '--name', $container, '--entrypoint', '/bin/true', $identity['image_id']],
                $log,
            ) |> trim(...);
            if ($containerId === '') {
                throw new InventoryError('Docker returned no inspection container');
            }
            self::run($runner, ['docker', 'export', '--output', $work . '/filesystem.tar', $containerId], $log);
            $entrypoint = dirname(__DIR__, 4) . '/scripts/packaged_inventory.php';
            self::run(
                $runner,
                [PHP_BINARY, $entrypoint, 'extract', '--archive', $work . '/filesystem.tar', '--root', $work . '/root'],
                $log,
            );
            self::run(
                $runner,
                [
                    PHP_BINARY,
                    $entrypoint,
                    'generate',
                    '--root',
                    $work . '/root',
                    '--image-inspect',
                    $output . '/image-inspect.json',
                    '--target',
                    $target,
                    '--revision',
                    $revision,
                    '--output',
                    $output,
                ],
                $log,
            );
            self::run(
                $runner,
                [
                    PHP_BINARY,
                    $entrypoint,
                    'verify',
                    '--root',
                    $work . '/root',
                    '--image-inspect',
                    $output . '/image-inspect.json',
                    '--inventory',
                    $output . '/inventory.json',
                    '--revision',
                    $revision,
                ],
                $log,
            );
            return Json::read($output . '/inventory.json');
        } catch (\Throwable $error) {
            file_put_contents($log, $error->getMessage() . "\n", FILE_APPEND);
            throw $error;
        } finally {
            self::cleanup($session);
        }
    }

    public static function cleanup(string $session): void
    {
        $statePath = $session . '/state.json';
        if (!file_exists($statePath)) {
            if (is_dir($session) && !is_link($session) && array_diff(scandir($session) ?: [], ['.', '..']) === []) {
                rmdir($session);
            }
            return;
        }
        $state = AtomicFile::readJson($statePath);
        $container = $state['container'];
        if (!is_string($container) || !preg_match('/^baander-application-inventory-([a-f0-9]{16})$/D', $container, $match)) {
            throw new InventoryError('Invalid inspection container receipt');
        }
        $work = rtrim(sys_get_temp_dir(), '/') . '/baander-application-inventory.' . $match[1];
        if (($state['work'] ?? null) !== $work || !is_string($state['output'] ?? null)) {
            throw new InventoryError('Invalid inventory cleanup receipt');
        }
        $log = $state['output'] . '/collection.log';
        if ($state['create_started'] ?? false) {
            try {
                self::run(new ProcessRunner(), ['docker', 'rm', '-v', '--', $container], $log, timeout: 30);
            } catch (\Throwable $error) {
                file_put_contents($log, 'Inspection container cleanup failed: ' . $error->getMessage() . "\n", FILE_APPEND);
            }
        }
        self::remove($work);
        unlink($statePath);
        rmdir($session);
    }

    /** @param non-empty-list<string> $argv */
    private static function run(ProcessRunner $runner, array $argv, string $log, ?float $timeout = null): string
    {
        $result = $runner->run(new ProcessSpec($argv, environment: getenv(), timeout: $timeout, processGroup: true));
        file_put_contents($log, $result->stdout . $result->stderr, FILE_APPEND);
        if ($result->stderr !== '') {
            fwrite(STDERR, $result->stderr);
        }
        if ($result->exitCode !== 0) {
            throw new InventoryError('Command failed (' . $result->exitCode . '): ' . implode(' ', $argv), $result->exitCode);
        }
        return $result->stdout;
    }

    public static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            chmod($path, 0700);
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                self::remove($path . '/' . $entry);
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
