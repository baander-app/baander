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
        mkdir($this->root . '/source/scripts', 0700, true);
        mkdir($this->root . '/source/docker/dev/systemd', 0700, true);
        file_put_contents($this->root . '/source/scripts/dev-environment.php', <<<'PHP'
<?php
require __DIR__ . '/bootstrap.php';
Baander\Tooling\Runtime\Requirements::check(false);
if (($argv[1] ?? '') === '--probe-package') {
    $runner = new Baander\Tooling\Process\ProcessRunner();
    $result = $runner->run(new Baander\Tooling\Process\ProcessSpec(argv: [PHP_BINARY, '-r', 'echo "package-helper";'], processGroup: true));
    echo json_encode(['stdout' => $result->stdout, 'exit' => $result->exitCode, 'source' => new ReflectionClass($runner)->getFileName()], JSON_THROW_ON_ERROR);
} else {
    echo 'controller';
}
PHP);
        copy(dirname(__DIR__, 2) . '/bootstrap.php', $this->root . '/source/scripts/bootstrap.php');
        $repository = dirname(__DIR__, 3);
        $this->copyFixtureTree($repository . '/packages/baander-tooling', $this->root . '/source/packages/baander-tooling');
        $this->copyFixtureTree($repository . '/scripts/vendor', $this->root . '/source/scripts/vendor');
        file_put_contents($this->root . '/source/docker/dev/private.key', 'do-not-copy');
        foreach (['baander-development.service.template', 'baander-development.timer'] as $name) {
            copy(dirname(__DIR__, 3) . '/docker/dev/systemd/' . $name, $this->root . '/source/docker/dev/systemd/' . $name);
        }
    }

    private function copyFixtureTree(string $source, string $destination): void
    {
        mkdir($destination, 0700, true);
        foreach (scandir($source) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $from = $source . '/' . $name;
            $to = $destination . '/' . $name;
            if (is_link($from)) {
                symlink('../../../packages/baander-tooling', $to);
            } elseif (is_dir($from)) {
                $this->copyFixtureTree($from, $to);
            } else {
                copy($from, $to);
            }
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
        self::assertFileExists(dirname($controller) . '/bootstrap.php');
        self::assertFileExists($this->store->root . '/controller/packages/baander-tooling/src/Process/ProcessRunner.php');
        self::assertSame('../../../packages/baander-tooling', readlink(dirname($controller) . '/vendor/baander/tooling'));
        rename($this->root . '/source', $this->root . '/source-offline');
        self::assertFileExists(dirname($controller) . '/vendor/autoload.php');
        self::assertSame('controller', new Runner()->run([PHP_BINARY, '-n', $controller])->stdout);
        $probe = json_decode(new Runner()->run([PHP_BINARY, $controller, '--probe-package'])->stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('package-helper', $probe['stdout']);
        self::assertSame(0, $probe['exit']);
        self::assertSame($this->store->root . '/controller/packages/baander-tooling/src/Process/ProcessRunner.php', $probe['source']);
        self::assertFileDoesNotExist(dirname($controller) . '/dev-environment.py');
        self::assertDirectoryDoesNotExist(dirname($controller) . '/dev_environment');
        self::assertFileDoesNotExist($this->store->root . '/controller/docker/dev/private.key');
        self::assertStringContainsString(Supervisor::systemdArgument((string) realpath(PHP_BINARY)), $unit);
        self::assertStringContainsString(Supervisor::systemdArgument($controller), $unit);
        self::assertStringNotContainsString($this->root . '/source', $unit);
        self::assertStringContainsString('Unit=' . $supervisor->unit . '.service', (string) file_get_contents($this->root . '/config/systemd/user/' . $supervisor->unit . '.timer'));
    }

    public function testOnlyTheDeclaredComposerPackageLinkCanEnterThePayload(): void
    {
        $link = $this->root . '/source/scripts/vendor/baander/tooling';
        unlink($link);
        mkdir($this->root . '/unexpected-package', 0700);
        symlink($this->root . '/unexpected-package', $link);
        $this->expectException(WorkflowError::class);
        $this->expectExceptionMessage('symlink controller payload');
        $this->supervisor($this->runner())->install();
    }

    public function testRepeatedInstallRetainsTheValidatedPackageLink(): void
    {
        $supervisor = $this->supervisor($this->runner());
        $supervisor->install();
        $supervisor->install();
        self::assertSame(
            realpath($this->store->root . '/controller/packages/baander-tooling'),
            realpath($this->store->root . '/controller/scripts/vendor/baander/tooling'),
        );
    }

    public function testOtherVendorSymlinksAreRejectedEvenWhenTheyPointToThePackage(): void
    {
        symlink($this->root . '/source/packages/baander-tooling', $this->root . '/source/scripts/vendor/unexpected-package');
        $this->expectException(WorkflowError::class);
        $this->expectExceptionMessage('symlink controller payload');
        $this->supervisor($this->runner())->install();
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
