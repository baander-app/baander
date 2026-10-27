<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\Supervisor;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/dev-core-runner.php';

final class DevelopmentSupervisorTest extends TestCase
{
    private string $root;
    private StateStore $store;
    private string|false $previousConfig;
    private string|false $previousPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-dev-supervisor-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->store = new StateStore($this->root . '/state with "quote" $cash %specifier');
        $this->previousConfig = getenv('XDG_CONFIG_HOME');
        $this->previousPath = getenv('PATH');
        putenv('XDG_CONFIG_HOME=' . $this->root . '/config');
        mkdir($this->root . '/source/scripts/tooling', 0700, true);
        mkdir($this->root . '/source/docker/dev/systemd', 0700, true);
        file_put_contents($this->root . '/source/scripts/dev-environment.php', '<?php echo "controller";');
        file_put_contents($this->root . '/source/scripts/tooling/bootstrap.php', '<?php // isolated loader');
        file_put_contents($this->root . '/source/docker/dev/private.key', 'do-not-copy');
        foreach (['baander-development.service.template', 'baander-development.timer'] as $name) {
            copy(dirname(__DIR__, 3) . '/docker/dev/systemd/' . $name, $this->root . '/source/docker/dev/systemd/' . $name);
        }
    }

    protected function tearDown(): void
    {
        putenv($this->previousConfig === false ? 'XDG_CONFIG_HOME' : 'XDG_CONFIG_HOME=' . $this->previousConfig);
        putenv($this->previousPath === false ? 'PATH' : 'PATH=' . $this->previousPath);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    /** @param (\Closure(array<string>):void)|null $callback */
    private function runner(?\Closure $callback = null): DevCoreRunner
    {
        return new DevCoreRunner(static function (array $arguments) use ($callback): ?ProcessResult {
            if ($callback !== null) {
                $callback($arguments);
            }
            return null;
        });
    }

    private function supervisor(Runner $runner): Supervisor
    {
        return new Supervisor($this->store, $runner, $this->root . '/source');
    }

    public function testCopiedPhpControllerReplacesLegacyWithAbsoluteQuotedExecutable(): void
    {
        mkdir($this->store->root . '/controller/scripts/dev_environment', 0700, true);
        file_put_contents($this->store->root . '/controller/scripts/dev_environment/updater.py', 'legacy');
        file_put_contents($this->store->root . '/controller/scripts/dev-environment.py', 'legacy');
        $supervisor = $this->supervisor($this->runner());
        $supervisor->install();
        $controller = $this->store->root . '/controller/scripts/dev-environment.php';
        $unit = (string) file_get_contents($this->root . '/config/systemd/user/' . $supervisor->unit . '.service');
        self::assertFileExists($controller);
        self::assertFileExists(dirname($controller) . '/tooling/bootstrap.php');
        self::assertFileDoesNotExist(dirname($controller) . '/dev-environment.py');
        self::assertDirectoryDoesNotExist(dirname($controller) . '/dev_environment');
        self::assertFileDoesNotExist($this->store->root . '/controller/docker/dev/private.key');
        self::assertStringContainsString(Supervisor::systemdArgument((string) realpath(PHP_BINARY)), $unit);
        self::assertStringContainsString(Supervisor::systemdArgument($controller), $unit);
        self::assertStringNotContainsString($this->root . '/source', $unit);
        self::assertStringContainsString('Unit=' . $supervisor->unit . '.service', (string) file_get_contents($this->root . '/config/systemd/user/' . $supervisor->unit . '.timer'));
    }

    public function testSearchPathPreservesAbsoluteEntriesWithoutSecrets(): void
    {
        putenv('PATH=/home/operator/.rd/bin:/opt/a "quote" %name $literal:/opt/back\\slash:/usr/bin:relative::/usr/bin');
        $supervisor = $this->supervisor($this->runner());
        $supervisor->install();
        $unit = (string) file_get_contents($this->root . '/config/systemd/user/' . $supervisor->unit . '.service');
        self::assertStringContainsString('Environment="PATH=/home/operator/.rd/bin:/opt/a \\"quote\\" %%name $literal:/opt/back\\\\slash:/usr/bin"', $unit);
        self::assertStringNotContainsString('relative', $unit);
    }

    public function testInvalidSearchPathIsRejectedBeforeAnyPublication(): void
    {
        foreach (['relative::another', "/usr/bin:/opt/bad\nInjected=yes"] as $path) {
            putenv('PATH=' . $path);
            try {
                $this->supervisor($this->runner())->install();
                self::fail('Unsafe PATH accepted');
            } catch (WorkflowError) {
                self::assertDirectoryDoesNotExist($this->store->root);
            }
        }
    }

    public function testRefreshHonorsDisableDuringPublicationAndKeepsLifecycleLock(): void
    {
        $this->store->save('persistent', ['id' => 'persistent', 'phase' => 'database_mutating']);
        $this->store->setEnabled('persistent', true);
        $lock = $this->store->lock('persistent');
        $runner = $this->runner(function (array $arguments): void {
            if ($arguments[2] === 'daemon-reload') {
                $this->store->setEnabled('persistent', false);
                try {
                    $this->store->lock('persistent', false);
                    self::fail('Lifecycle lock was released during publication');
                } catch (WorkflowError $error) {
                    self::assertStringContainsString('another operation', $error->getMessage());
                }
            }
        });
        try {
            $supervisor = $this->supervisor($runner);
            $supervisor->refresh();
            self::assertFalse($this->store->enabled('persistent'));
            self::assertSame(['systemctl', '--user', 'disable', '--now', $supervisor->unit . '.timer'], $runner->calls[array_key_last($runner->calls)]['arguments']);
            self::assertSame('database_mutating', $this->store->load('persistent')['phase']);
        } finally {
            $lock->release();
        }
    }

    public function testDisablePersistsIntentBeforeStoppingOnlyTimer(): void
    {
        $this->store->setEnabled('persistent', true);
        $runner = $this->runner(function (array $arguments): void {
            self::assertFalse($this->store->enabled('persistent'));
            self::assertSame('disable', $arguments[2]);
            self::assertStringEndsWith('.timer', $arguments[4]);
        });
        $this->supervisor($runner)->disable();
        self::assertCount(1, $runner->calls);
    }

    public function testEnableHonorsConcurrentDisableDuringPublication(): void
    {
        $runner = $this->runner(function (array $arguments): void {
            if ($arguments[2] === 'daemon-reload') {
                $this->store->setEnabled('persistent', false);
            }
        });
        $supervisor = $this->supervisor($runner);
        $supervisor->enable();
        self::assertFalse($this->store->enabled('persistent'));
        self::assertSame(['systemctl', '--user', 'disable', '--now', $supervisor->unit . '.timer'], $runner->calls[array_key_last($runner->calls)]['arguments']);
    }

    public function testFailedEnableRollsBackIntent(): void
    {
        $runner = $this->runner(static function (array $arguments): void {
            if ($arguments[2] === 'enable') {
                throw new WorkflowError('systemctl failed');
            }
        });
        try {
            $this->supervisor($runner)->enable();
            self::fail('Enable failure ignored');
        } catch (WorkflowError) {
            self::assertFalse($this->store->enabled('persistent'));
        }
    }
}
