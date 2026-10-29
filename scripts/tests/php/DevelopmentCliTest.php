<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Cli;
use Baander\Tooling\Development\Environment;
use Baander\Tooling\Tests\DevelopmentCommandExecutor;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use PHPUnit\Framework\TestCase;

final class DevelopmentCliTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = '/tmp/baander-cli-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/checkout', 0700);
        $this->sourceLayout($this->directory . '/checkout');
    }

    protected function tearDown(): void
    {
        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path) && !is_link($path)) {
                foreach (scandir($path) ?: [] as $name) {
                    if ($name !== '.' && $name !== '..') {
                        $remove($path . '/' . $name);
                    }
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        };
        $remove($this->directory);
        putenv('BAANDER_COMMAND');
    }

    public function testNewOwnershipRejectsUnsupportedWorkerTransportBeforeContainerCommands(): void
    {
        $previous = getenv('BAANDER_TOOLING_TARGET_FILE');
        $path = $this->directory . '/captured-target.json';
        $runner = new class extends DevelopmentCommandExecutor {
            /** @var list<list<string>> */
            public array $calls = [];
            public function execute(
                array $arguments,
                ?string $cwd = null,
                ?array $env = null,
                ?string $input = null,
                ?float $timeout = 120,
                bool $check = true,
                ?string $logPath = null,
                bool $interactive = false,
            ): ProcessResult {
                $this->calls[] = $arguments;
                if (in_array('info', $arguments, true)) {
                    return new ProcessResult(0, null, false, false, '{"ID":"daemon"}', '', 0);
                }
                throw new \RuntimeException('Unexpected Docker command.');
            }
        };
        try {
            putenv('BAANDER_TOOLING_TARGET_FILE=' . $path);
            foreach (['tcp://daemon.baander.app:2375', 'unix:///tmp/daemon,socket'] as $endpoint) {
                \Baander\Tooling\Filesystem\AtomicFile::writeJson(
                    $path,
                    new \Baander\Tooling\Docker\Target('/fake/docker', $endpoint, 'daemon', null, null)->toArray(),
                );
                try {
                    Cli::dockerTarget($runner, \Baander\Tooling\Configuration\ToolingConfiguration::resolve(), $this->directory . '/unused-snapshot', getenv());
                    self::fail('Unsupported development transport was admitted.');
                } catch (\RuntimeException $error) {
                    self::assertNotSame('Unexpected Docker command.', $error->getMessage());
                }
            }
            self::assertCount(2, $runner->calls);
            self::assertTrue(array_all($runner->calls, static fn(array $arguments): bool => in_array('info', $arguments, true)));
            self::assertDirectoryDoesNotExist($this->directory . '/unused-snapshot');
        } finally {
            putenv($previous === false ? 'BAANDER_TOOLING_TARGET_FILE' : 'BAANDER_TOOLING_TARGET_FILE=' . $previous);
        }
    }

    /** @param array<string,mixed> $options
     * @param array<string,string>|null $environment
     * @return array<string,mixed>
     */
    private function configure(StateStore $store, DevelopmentCommandExecutor $runner, string $root, string $repository, array $options, ?array $environment = null): array
    {
        return Cli::configure(
            $store,
            $runner,
            $root,
            $repository,
            $options,
            environment: $environment ?? array_replace(getenv(), ['CI' => '0']),
        );
    }

    private function sourceLayout(string $root): void
    {
        mkdir($root . '/packages/baander-tooling/resources', 0700, true);
        file_put_contents($root . '/packages/baander-tooling/resources/application-layout.json', '{"version":1,"root":"/var/www/html"}');
    }

    /** @return array{StateStore,array<string,mixed>} */
    private function savedLibraryInstance(string $phase = 'stopped'): array
    {
        mkdir($this->directory . '/movies', 0700);
        mkdir($this->directory . '/replacement', 0700);
        mkdir($this->directory . '/manual', 0700);
        $store = new StateStore($this->directory . '/state');
        $instance = [
            'id' => 'persistent', 'repository' => 'repository', 'phase' => $phase,
            'version' => 1, 'docker' => DevelopmentCommandExecutor::target(),
            'media' => [
                ['source' => $this->directory . '/manual', 'target' => '/manual'],
                ['source' => $this->directory . '/movies', 'target' => '/media/movies'],
            ],
            'libraries' => [['slug' => 'movies', 'name' => 'Movies', 'type' => 'movie', 'source' => $this->directory . '/movies', 'target' => '/media/movies']],
        ];
        $configuration = \Baander\Tooling\Configuration\ToolingConfiguration::resolve(environment: getenv());
        $instance['tooling'] = $configuration->toArray();
        $store->save('persistent', $instance);
        file_put_contents($store->directory('persistent') . '/env.json', '{}');
        return [$store, $instance];
    }

    private function localLibrary(string $source): void
    {
        file_put_contents($this->directory . '/checkout/dev.local.json', json_encode(['libraries' => [['slug' => 'movies', 'name' => 'Movies', 'type' => 'movie', 'path' => $source]]], JSON_THROW_ON_ERROR));
    }

    public function testAbsentAndOmittedConfigurationPreserveSavedLibrariesAndMedia(): void
    {
        [$store, $saved] = $this->savedLibraryInstance();
        foreach ([null, '{}'] as $content) {
            if ($content !== null) {
                file_put_contents($this->directory . '/checkout/dev.local.json', $content);
            }
            $instance = $this->configure($store, new DevelopmentCommandExecutor(), $this->directory . '/checkout', 'repository', Cli::parse(['up', '--instance', 'persistent']));
            self::assertEquals($saved, $instance);
        }
    }

    public function testEmptyConfigurationClearsOwnedMountsAndKeepsManualMounts(): void
    {
        [$store, $saved] = $this->savedLibraryInstance();
        file_put_contents($this->directory . '/checkout/dev.local.json', '{"libraries":[]}');
        $instance = $this->configure($store, new DevelopmentCommandExecutor(), $this->directory . '/checkout', 'repository', Cli::parse(['up', '--instance', 'persistent']));
        self::assertSame([], $instance['libraries']);
        self::assertSame([$saved['media'][0]], $instance['media']);
    }

    public function testCapturedEnvironmentDefinesConfigurationAfterAmbientChanges(): void
    {
        [$store] = $this->savedLibraryInstance();
        $previous = getenv('BAANDER_TEST_TIMEOUT');
        $captured = array_replace(getenv(), ['BAANDER_TEST_TIMEOUT' => '7']);
        try {
            putenv('BAANDER_TEST_TIMEOUT=99');
            $instance = $this->configure(
                $store,
                new DevelopmentCommandExecutor(),
                $this->directory . '/checkout',
                'repository',
                Cli::parse(['up', '--instance', 'persistent']),
                environment: $captured,
            );
            self::assertSame(7.0, $instance['tooling']['budgets']['backend']);
            self::assertSame('environment', $instance['tooling']['provenance']['budgets.backend']);
        } finally {
            putenv($previous === false ? 'BAANDER_TEST_TIMEOUT' : 'BAANDER_TEST_TIMEOUT=' . $previous);
        }
    }

    public function testCommandVariableUsesTheCapturedInvocationEnvironment(): void
    {
        putenv('BAANDER_COMMAND=php bin/console changed');
        self::assertSame(['php', 'bin/console', 'captured'], Cli::execCommand(
            Cli::parse(['exec', '--command-env', 'BAANDER_COMMAND']),
            ['BAANDER_COMMAND' => 'php bin/console captured'],
        ));
    }

    public function testCliOverridePersistsEffectiveLibrarySourceAndStableTarget(): void
    {
        [$store] = $this->savedLibraryInstance();
        $this->localLibrary($this->directory . '/movies');
        $instance = $this->configure($store, new DevelopmentCommandExecutor(), $this->directory . '/checkout', 'repository', Cli::parse(['up', '--instance', 'persistent', '--media', $this->directory . '/replacement=/media/movies']));
        self::assertSame($this->directory . '/replacement', $instance['libraries'][0]['source']);
        self::assertSame('/media/movies', $instance['libraries'][0]['target']);
        self::assertEquals($instance, $store->load('persistent'));
        unlink($this->directory . '/checkout/dev.local.json');
        self::assertEquals($instance, $this->configure($store, new DevelopmentCommandExecutor(), $this->directory . '/checkout', 'repository', Cli::parse(['up', '--instance', 'persistent'])));
    }

    public function testNewPreviewInheritsDefinitionsAndExplicitEmptyRetainsManualMounts(): void
    {
        [$store, $saved] = $this->savedLibraryInstance();
        $runner = $this->createStub(DevelopmentCommandExecutor::class);
        $runner->method('run')->willReturnCallback(static function (ProcessSpec $command): ProcessResult {
            $output = in_array('inspect', $command->argv, true) ? '[{"Endpoints":{"docker":{"Host":"unix:///var/run/docker.sock"}}}]' : (in_array('show', $command->argv, true) ? 'fixture-context' : '{"ID":"fixture-daemon"}');
            return new ProcessResult(0, null, false, false, $output, '', 0);
        });
        file_put_contents($this->directory . '/docker', '#!/bin/sh');
        chmod($this->directory . '/docker', 0700);
        $originalPath = getenv('PATH');
        putenv('PATH=' . $this->directory . PATH_SEPARATOR . ($originalPath ?: ''));
        try {
            foreach ([false, true] as $clear) {
                $root = $this->directory . ($clear ? '/empty-preview' : '/inherited-preview');
                mkdir($root, 0700);
                $this->sourceLayout($root);
                if ($clear) {
                    file_put_contents($root . '/dev.local.json', '{"libraries":[]}');
                }
                $id = Environment::previewId($root, 'repository');
                mkdir($store->directory($id), 0700);
                file_put_contents($store->directory($id) . '/env.json', '{}');
                $instance = $this->configure($store, $runner, $root, 'repository', Cli::parse(['up']));
                self::assertEquals($clear ? [] : $saved['libraries'], $instance['libraries']);
                self::assertSame($clear ? [$saved['media'][0]] : $saved['media'], $instance['media']);
            }
        } finally {
            $originalPath === false ? putenv('PATH') : putenv('PATH=' . $originalPath);
        }
    }

    public function testRunningInstanceRejectsChangedAndClearedMediaWithoutSaving(): void
    {
        [$store] = $this->savedLibraryInstance('ready');
        $path = $store->directory('persistent') . '/state.json';
        $saved = file_get_contents($path);
        $environment = file_get_contents($store->directory('persistent') . '/env.json');
        foreach ([false, true] as $clear) {
            $this->localLibrary($this->directory . '/replacement');
            if ($clear) {
                file_put_contents($this->directory . '/checkout/dev.local.json', '{"libraries":[]}');
            }
            try {
                $this->configure($store, new DevelopmentCommandExecutor(), $this->directory . '/checkout', 'repository', Cli::parse(['up', '--instance', 'persistent']));
                self::fail('Changing running media must fail.');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('Stop this instance', $error->getMessage());
            }
            self::assertSame($saved, file_get_contents($path));
            self::assertSame($environment, file_get_contents($store->directory('persistent') . '/env.json'));
        }
    }

    public function testInvalidMountOverlapFailsBeforeLockCreatesState(): void
    {
        $this->localLibrary($this->directory . '/checkout');
        foreach (['/media', '/etc'] as $target) {
            try {
                $this->configure(new StateStore($this->directory . '/state'), new DevelopmentCommandExecutor(), $this->directory . '/checkout', 'repository', Cli::parse(['up', '--media', $this->directory . '/checkout=' . $target]));
                self::fail('Overlapping media must fail.');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('overlap', $error->getMessage());
            }
            self::assertDirectoryDoesNotExist($this->directory . '/state');
        }
    }

    public function testInvalidLibraryDefinitionsFailBeforeCreatingStateOrSecrets(): void
    {
        $entry = ['slug' => 'movies', 'name' => 'Movies', 'type' => 'movie', 'path' => $this->directory . '/checkout'];
        foreach ([[$entry, $entry], [array_replace($entry, ['type' => 'invalid'])], [array_replace($entry, ['path' => 'relative'])]] as $libraries) {
            file_put_contents($this->directory . '/checkout/dev.local.json', json_encode(['libraries' => $libraries], JSON_THROW_ON_ERROR));
            try {
                $this->configure(new StateStore($this->directory . '/state'), new DevelopmentCommandExecutor(), $this->directory . '/checkout', 'repository', Cli::parse(['up']));
                self::fail('Invalid libraries must fail.');
            } catch (WorkflowError $error) {
                self::assertNotSame('', $error->getMessage());
            }
            self::assertDirectoryDoesNotExist($this->directory . '/state');
        }
    }

    public function testMalformedLocalConfigurationFailsBeforeCreatingState(): void
    {
        file_put_contents($this->directory . '/checkout/dev.local.json', '{broken');
        try {
            $this->configure(new StateStore($this->directory . '/state'), new DevelopmentCommandExecutor(), $this->directory . '/checkout', 'repository', Cli::parse(['up']));
            self::fail('Malformed local configuration must fail.');
        } catch (WorkflowError $error) {
            self::assertStringContainsString('dev.local.json', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->directory . '/state');
    }

    public function testCommandEnvironmentPreservesQuotedValuesAndMetacharacters(): void
    {
        putenv("BAANDER_COMMAND=printf '%s' 'quoted value' ';' '\$(touch marker)'");
        self::assertSame(
            ['printf', '%s', 'quoted value', ';', '$(touch marker)'],
            Cli::execCommand(Cli::parse(['exec', '--command-env', 'BAANDER_COMMAND'])),
        );
    }

    public function testExplicitShellCommandIsPassedWhole(): void
    {
        $payload = 'printf \'%s\' "quoted value"; printf \'%s\' "$(pwd)"';
        putenv('BAANDER_COMMAND=' . $payload);
        self::assertSame(
            ['bash', '-c', $payload],
            Cli::execCommand(Cli::parse(['exec', '--shell-command-env', 'BAANDER_COMMAND'])),
        );
        self::assertSame(['php', '-v'], Cli::execCommand(Cli::parse(['exec', '--', 'php', '-v'])));
    }

    public function testEmptyAndConflictingCommandSourcesFail(): void
    {
        foreach ([
            ['exec'],
            ['exec', '--command-env', 'BAANDER_COMMAND'],
            ['exec', '--command-env', 'BAANDER_COMMAND', '--', 'php'],
        ] as $arguments) {
            try {
                Cli::execCommand(Cli::parse($arguments));
                self::fail('A missing or conflicting command must fail.');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('command', $error->getMessage());
            }
        }
    }

    public function testStateInsideCheckoutFailsBeforeCreation(): void
    {
        foreach ([$this->directory . '/checkout', $this->directory . '/checkout/private-state'] as $path) {
            try {
                $this->configure(
                    new StateStore($path),
                    new DevelopmentCommandExecutor(),
                    $this->directory . '/checkout',
                    'repository',
                    Cli::parse(['up']),
                );
                self::fail('State inside the worktree must fail.');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('outside the worktree', $error->getMessage());
            }
        }
        self::assertSame(['.', '..', 'packages'], scandir($this->directory . '/checkout'));
    }

    public function testMediaCannotExposeStateOrPrivateSubdirectories(): void
    {
        $store = new StateStore($this->directory . '/state');
        mkdir($store->root, 0700);
        mkdir($store->root . '/gateway', 0700);
        foreach ([$this->directory, $store->root, $store->root . '/gateway'] as $source) {
            try {
                $this->configure(
                    $store,
                    new DevelopmentCommandExecutor(),
                    $this->directory . '/checkout',
                    'repository',
                    Cli::parse(['up', '--media', $source]),
                );
                self::fail('Media exposing state must fail.');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('Media directories', $error->getMessage());
            }
        }
        self::assertSame([], glob($store->root . '/*/state.json'));
    }

    public function testInheritedAndExistingMediaRemainValidated(): void
    {
        $store = new StateStore($this->directory . '/state');
        $store->save(
            'persistent',
            [
                'id' => 'persistent',
                'repository' => 'repository',
                'media' => [['source' => $this->directory, 'target' => '/media']],
            ],
        );
        $saved = file_get_contents($store->directory('persistent') . '/state.json');
        foreach ([['up'], ['up', '--instance', 'persistent']] as $arguments) {
            try {
                $this->configure($store, new DevelopmentCommandExecutor(), $this->directory . '/checkout', 'repository', Cli::parse($arguments));
                self::fail('Saved media exposing state must fail.');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('Media directories', $error->getMessage());
            }
        }
        self::assertSame($saved, file_get_contents($store->directory('persistent') . '/state.json'));
    }

    public function testStoppedInstanceCannotProduceAReadyUrl(): void
    {
        $this->expectException(WorkflowError::class);
        Cli::readyUrl(['phase' => 'stopped', 'readiness' => null, 'hostname' => 'dev.baander.app']);
    }

    public function testUnknownOptionsAndInvalidChoicesAreRejected(): void
    {
        foreach ([
            [['up', '--instance', 'preview'], 'persistent'],
            [['exec', '--service', 'invalid'], 'service'],
            [['status', '--unknown'], 'Unknown'],
            [['exec', '--command-env', 'A', '--shell-command-env', 'B'], 'Choose'],
        ] as [$arguments, $diagnostic]) {
            try {
                Cli::parse($arguments);
                self::fail('Invalid CLI options must fail.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($diagnostic, $error->getMessage());
            }
        }
    }

    public function testMakeExportsCommandWithoutExecutingHostMetacharacters(): void
    {
        $root = dirname(__DIR__, 3);
        $capture = __DIR__ . '/Fixtures/dev-cli-capture.php';
        $output = $this->directory . '/capture.json';
        $marker = $this->directory . '/host-marker';
        $payload = "printf '%s' 'quoted value'; touch " . $marker . '; $(shell touch ' . $marker . ')';
        foreach ([
            'exec' => ['--command-env', 'cmd'],
            'exec-by-root' => ['--user', 'root', '--command-env', 'cmd'],
            'exec-bash' => ['--shell-command-env', 'cmd'],
        ] as $target => $expected) {
            $result = new ProcessRunner()->run(new ProcessSpec(
                argv: [
                    'make',
                    '--no-print-directory',
                    '-f',
                    $root . '/Makefile',
                    $target,
                    'INSIDE_DOCKER_CONTAINER=0',
                    'DEV_ENVIRONMENT=' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($capture),
                    'cmd=' . $payload,
                ],
                cwd: $this->directory,
                environment: [...getenv(), 'CAPTURE_RESULT' => $output],
                timeout: 10,
            ));
            self::assertSame(0, $result->exitCode, $result->stderr);
            self::assertSame(
                [['exec', ...$expected], $payload],
                json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR),
            );
            self::assertFileDoesNotExist($marker);
        }
        $payload = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($capture) . " 'quoted value' ';' touch " . escapeshellarg($marker);
        $result = new ProcessRunner()->run(new ProcessSpec(
            argv: [
                'make',
                '--no-print-directory',
                '-f',
                $root . '/Makefile',
                'exec',
                'INSIDE_DOCKER_CONTAINER=1',
                'cmd=' . $payload,
            ],
            cwd: $this->directory,
            environment: [...getenv(), 'CAPTURE_RESULT' => $output],
            timeout: 10,
        ));
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame(
            [['quoted value', ';', 'touch', $marker], $payload],
            json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertFileDoesNotExist($marker);
    }

    public function testHostSetupRejectsStateInsideCheckoutBeforeWritingFiles(): void
    {
        $runner = new DevelopmentCommandExecutor();
        $runner->execute(['git', 'init', '-q', $this->directory . '/checkout']);
        $result = new ProcessRunner()->run(new ProcessSpec(
            argv: [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/dev-environment.php',
                '--state-root',
                $this->directory . '/checkout/state',
                'host-setup',
            ],
            cwd: $this->directory . '/checkout',
            environment: getenv(),
            timeout: 10,
        ));
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('outside the worktree', $result->stderr);
        self::assertDirectoryDoesNotExist($this->directory . '/checkout/state');
    }

    public function testRuntimeFailurePrecedesAnyStateChange(): void
    {
        $result = new ProcessRunner()->run(new ProcessSpec(
            argv: [
                PHP_BINARY,
                '-n',
                dirname(__DIR__, 2) . '/dev-environment.php',
                '--state-root',
                $this->directory . '/state',
                'host-setup',
            ],
            cwd: $this->directory . '/checkout',
            environment: getenv(),
            timeout: 10,
        ));
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('swoole is required', $result->stderr);
        self::assertDirectoryDoesNotExist($this->directory . '/state');
    }

    public function testHostSetupOutsideGitWritesOnlyItsExplicitStateRoot(): void
    {
        $result = new ProcessRunner()->run(new ProcessSpec(
            argv: [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/dev-environment.php',
                '--state-root',
                $this->directory . '/state',
                'host-setup',
            ],
            cwd: $this->directory . '/checkout',
            environment: getenv(),
            timeout: 10,
        ));
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertFileExists($this->directory . '/state/host-setup/README.md');
        self::assertStringContainsString($this->directory . '/state/host-setup/README.md', $result->stdout);
        self::assertSame(['.', '..', 'packages'], scandir($this->directory . '/checkout'));
    }

    public function testWorktreeWithoutRemoteKeepsTheSharedRepositoryIdentity(): void
    {
        $runner = new DevelopmentCommandExecutor();
        $root = $this->directory . '/checkout';
        $runner->execute(['git', 'init', '-q', $root]);
        $runner->execute(
            [
                'git',
                '-c',
                'user.name=Tooling',
                '-c',
                'user.email=tooling@baander.app',
                'commit',
                '--allow-empty',
                '-qm',
                'fixture',
            ],
            cwd: $root,
        );
        $runner->execute(['git', 'worktree', 'add', '--detach', $this->directory . '/worktree'], cwd: $root);
        [$checkout, $identity] = Cli::repositoryContext($runner, $this->directory . '/worktree');
        self::assertSame($this->directory . '/worktree', $checkout);
        self::assertSame(realpath($root . '/.git'), $identity);
    }
}
