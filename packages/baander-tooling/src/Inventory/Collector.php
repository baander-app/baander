<?php

declare(strict_types=1);

namespace Baander\Tooling\Inventory;

use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Docker\TargetResolver;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Resources\ResourceKind;
use Baander\Tooling\Resources\ResourceLedger;

final class Collector
{
    /** @return array<string, mixed> */
    public static function collect(string $image, string $target, string $revision, string $output, string $session, string $loader): array
    {
        if ($image === '' || $revision === '' || $output === '' || !in_array($target, ['production', 'worker'], true)) {
            throw new InventoryError('Image, production|worker target, revision and output are required', 2);
        }
        $environment = getenv();
        $configuration = ToolingConfiguration::resolve(environment: $environment);
        $executor = new EngineExecutor(ExecutionContext::bounded($configuration->budgets->seconds('backend'), $environment));
        $docker = new Client($executor, new TargetResolver($executor, $environment)->resolve($configuration->docker, $session . '/transport'), $environment);
        ApplicationInventory::directory($output);
        $log = $output . '/collection.log';
        file_put_contents($log, '');
        $token = bin2hex(random_bytes(8));
        $work = rtrim(sys_get_temp_dir(), '/') . '/baander-application-inventory.' . $token;
        $container = 'baander-application-inventory-' . $token;
        $state = ['work' => $work, 'output' => $output, 'cleanupSeconds' => $configuration->budgets->seconds('cleanup')];
        AtomicFile::writeJson($session . '/state.json', $state);
        $ledger = new ResourceLedger($session . '/resources.json', $docker, $environment);
        try {
            return $ledger->scoped(static function () use ($work, $docker, $image, $output, $target, $revision, $container, $log, $executor, $loader, $environment, $ledger): array {
                ApplicationInventory::directory($work);
                $inspect = $docker->run(['image', 'inspect', '--', $image])->stdout;
                file_put_contents($log, $inspect, FILE_APPEND);
                file_put_contents($output . '/image-inspect.json', $inspect);
                $identity = ApplicationInventory::imageIdentity(Json::read($output . '/image-inspect.json'), $target);
                ApplicationInventory::checkIdentity($identity, $revision);
                $containerId = $ledger->create(ResourceKind::Container, $container, ['--entrypoint', '/bin/true', $identity['image_id']]);
                file_put_contents($log, $containerId . "\n", FILE_APPEND);
                $docker->run(['export', '--output', $work . '/filesystem.tar', $containerId], logPath: $log);
                foreach ([
                    ['extract', '--archive', $work . '/filesystem.tar', '--root', $work . '/root'],
                    ['generate', '--root', $work . '/root', '--image-inspect', $output . '/image-inspect.json', '--target', $target, '--revision', $revision, '--output', $output],
                    ['verify', '--root', $work . '/root', '--image-inspect', $output . '/image-inspect.json', '--inventory', $output . '/inventory.json', '--revision', $revision],
                ] as $arguments) {
                    $executor->run(Worker::command($loader, $arguments, $environment, $log));
                }
                return Json::read($output . '/inventory.json');
            }, $state['cleanupSeconds']);
        } catch (\Throwable $error) {
            file_put_contents($log, $error->getMessage() . "\n", FILE_APPEND);
            throw $error;
        } finally {
            $executor->close();
            if (!file_exists($session . '/resources.json') && !is_link($session . '/resources.json')) {
                self::cleanup($session);
            }
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
        $work = $state['work'] ?? null;
        if (!is_string($work) || preg_match('~^' . preg_quote(rtrim(sys_get_temp_dir(), '/') . '/baander-application-inventory.', '~') . '[a-f0-9]{16}$~D', $work) !== 1
            || !is_string($state['output'] ?? null) || (!is_int($state['cleanupSeconds'] ?? null) && !is_float($state['cleanupSeconds'] ?? null))) {
            throw new InventoryError('Invalid inventory cleanup receipt');
        }
        if (file_exists($session . '/resources.json') || is_link($session . '/resources.json')) {
            $report = ResourceLedger::recover($session . '/resources.json', getenv(), $state['cleanupSeconds']);
            if (!$report->successful()) {
                throw new InventoryError($report->message(), 1);
            }
        }
        self::remove($work);
        self::remove($session);
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
