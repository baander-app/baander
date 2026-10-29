<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\{Environment, StateStore};
use Baander\Tooling\Docker\Target;
use Baander\Tooling\Filesystem\{AtomicFile, PrivateDirectory};
use Baander\Tooling\Process\{ProcessResult, ProcessRunner, ProcessSpec};
use PHPUnit\Framework\TestCase;

final class DevelopmentCliBoundaryTest extends TestCase
{
    private string $root;
    private string $repository;
    private StateStore $store;
    private string $entrypoint;
    private const string ID = 'preview-0123456789abcdef';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-cli-boundary-' . bin2hex(random_bytes(8));
        $this->repository = $this->root . '/repository';
        PrivateDirectory::prepare($this->repository);
        PrivateDirectory::prepare($this->root . '/bin');
        $this->store = new StateStore($this->root . '/state');
        $this->entrypoint = dirname(__DIR__, 2) . '/dev-environment.php';
        $this->executable('git', ['repository' => $this->repository]);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /** @param array<string,mixed> $configuration */
    private function executable(string $name, array $configuration): string
    {
        $configurationFile = $this->root . '/' . $name . '.json';
        AtomicFile::writeJson($configurationFile, $configuration);
        $path = $this->root . '/bin/' . $name;
        file_put_contents($path, '#!' . PHP_BINARY . "\n<?php\n\$_SERVER['argv'] = [\$_SERVER['argv'][0], " . var_export($configurationFile, true) . ", ...array_slice(\$_SERVER['argv'], 1)]"
            . ";\nrequire " . var_export(__DIR__ . '/Fixtures/development-cli-git.php', true) . ";\n");
        chmod($path, 0700);
        return $path;
    }

    /** @return array<string,string> */
    private function environment(string $budget): array
    {
        return array_replace(Environment::clean(), ['PATH' => $this->root . '/bin:' . (getenv('PATH') ?: '/usr/bin:/bin'),
            'BAANDER_TEST_TIMEOUT' => $budget]);
    }

    /** @return list<string> */
    private function arguments(string $command): array
    {
        return [PHP_BINARY, $this->entrypoint, '--state-root', $this->store->root, $command, '--instance', self::ID,
            ...($command === 'status' ? ['--json'] : [])];
    }

    private function runCli(string $command, string $budget = '10'): ProcessResult
    {
        return new ProcessRunner()->run(new ProcessSpec(
            $this->arguments($command),
            cwd: $this->repository,
            environment: $this->environment($budget),
            timeout: 5,
            processGroup: true,
        ));
    }

    /** @return array<string,mixed> */
    private function savedInstance(int $version = 1): array
    {
        $docker = $this->executable('docker', ['mode' => 'docker', 'calls' => $this->root . '/docker-calls.jsonl']);
        $instance = ['version' => $version, 'id' => self::ID, 'kind' => 'preview', 'project' => 'cli-boundary',
            'hostname' => 'boundary.baander.test', 'phase' => 'stopped', 'worktree' => $this->repository,
            'repository' => 'https://forgejo.baander.app/baander/baander.git',
            'compose_file' => $this->root . '/saved-compose.json',
            'docker' => new Target($docker, 'unix:///tmp/baander.app-cli-boundary.sock', 'boundary-daemon', null, null)->toArray(),
            'media' => [['source' => $this->root . '/missing-media', 'target' => '/media/movies']],
            'libraries' => [['slug' => 'movies', 'name' => 'Movies', 'type' => 'movies',
                'source' => $this->root . '/missing-media', 'target' => '/media/movies']]];
        AtomicFile::write($this->repository . '/dev.local.json', '{invalid current configuration');
        AtomicFile::writeJson($instance['compose_file'], ['services' => []]);
        AtomicFile::writeJson($this->store->directory(self::ID) . '/state.json', $instance);
        return $instance;
    }

    public function testCliDeadlineRemainsTimeoutWhenTerminatedChildExitsZero(): void
    {
        $marker = $this->root . '/git-started';
        $this->executable('git', ['repository' => $this->repository, 'hang' => true, 'marker' => $marker]);
        $result = $this->runCli('status', '0.4');
        self::assertFalse($result->timedOut, 'Outer supervision timed out instead of the CLI budget.');
        self::assertSame(124, $result->exitCode, $result->stderr);
        self::assertSame('0', file_get_contents($marker . '.stopped'));
        self::assertDirectoryDoesNotExist($this->store->root);
    }

    public function testCliStatusDoesNotSwallowTimeoutDuringSnapshotInspection(): void
    {
        $this->savedInstance();
        $marker = $this->root . '/snapshot-started';
        $this->executable('git', ['repository' => $this->repository, 'hang-snapshot' => true, 'marker' => $marker]);
        $result = $this->runCli('status', '4');
        self::assertSame(124, $result->exitCode, $result->stderr);
        self::assertFileExists($marker);
        self::assertSame('0', file_get_contents($marker . '.stopped'));
    }

    public function testOrdinaryCommandUsesResolvedLocalWorkflowBudget(): void
    {
        $instance = $this->savedInstance();
        $instance['phase'] = 'ready';
        AtomicFile::writeJson($this->store->directory(self::ID) . '/state.json', $instance);
        AtomicFile::writeJson($this->repository . '/dev.local.json', ['tooling' => ['budgets' => ['backend' => 2.5]]]);
        $marker = $this->root . '/compose-started';
        $this->executable('docker', ['mode' => 'docker', 'calls' => $this->root . '/docker-calls.jsonl', 'hang-command' => true, 'marker' => $marker]);
        $environment = $this->environment('10');
        unset($environment['BAANDER_TEST_TIMEOUT']);
        $result = new ProcessRunner()->run(new ProcessSpec(
            [...$this->arguments('exec'), '--', 'php', 'bin/console', '--version'],
            cwd: $this->repository,
            environment: $environment,
            timeout: 8,
            processGroup: true,
        ));
        self::assertSame(124, $result->exitCode, $result->stderr);
        self::assertFileExists($marker);
        self::assertSame('0', file_get_contents($marker . '.stopped'));
    }

    public function testCliCancellationRemainsSignalStatusWhenChildExitsZero(): void
    {
        $marker = $this->root . '/git-started';
        $this->executable('git', ['repository' => $this->repository, 'hang' => true, 'marker' => $marker]);
        $process = proc_open(
            ['env', '--default-signal=INT,TERM', '--', ...$this->arguments('status')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repository,
            $this->environment('10'),
        );
        self::assertIsResource($process);
        try {
            fclose($pipes[0]);
            $deadline = hrtime(true) / 1_000_000_000 + 5;
            while (!is_file($marker) && hrtime(true) / 1_000_000_000 < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($marker);
            self::assertTrue(proc_terminate($process, SIGTERM));
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (hrtime(true) / 1_000_000_000 >= $deadline) {
                    self::fail('CLI cancellation did not finish within supervision budget.');
                }
                usleep(10000);
            } while (true);
            self::assertSame(143, $status['exitcode'], stream_get_contents($pipes[2]));
            self::assertSame('0', file_get_contents($marker . '.stopped'));
            self::assertDirectoryDoesNotExist($this->store->root);
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, SIGKILL);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
    }

    public function testStatusIgnoresInvalidCurrentConfigurationAndMissingMedia(): void
    {
        $this->savedInstance();
        $result = $this->runCli('status');
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame('stopped', json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR)['phase']);
        self::assertFileDoesNotExist($this->root . '/docker-calls.jsonl');
    }

    public function testFutureSchemaRemainsAvailableToRawCliStatus(): void
    {
        $this->savedInstance(version: 99);
        $result = $this->runCli('status');
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame(self::ID, json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR)['id']);
    }

    public function testDownUsesSavedOwnershipDespiteInvalidConfigurationAndMissingMedia(): void
    {
        $this->savedInstance();
        $result = $this->runCli('down');
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame('stopped', $this->store->load(self::ID)['phase']);
        $commands = array_map(
            static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->root . '/docker-calls.jsonl', FILE_IGNORE_NEW_LINES),
        );
        self::assertTrue(array_any($commands, static fn(array $arguments): bool => in_array('down', $arguments, true)));
        self::assertFalse(array_any($commands, static fn(array $arguments): bool => in_array('pull', $arguments, true) || in_array('build', $arguments, true)));
    }
}
