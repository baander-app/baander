<?php

declare(strict_types=1);

namespace Baander\Tests\Tooling;

use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use PHPUnit\Framework\TestCase;

final class WebRuntimeWorkflowTest extends TestCase
{
    private string $directory;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-web-workflow-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $source = str_replace(
            "dirname(__DIR__, 4) . '/vendor/autoload.php'",
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
            file_get_contents(__DIR__ . '/Fixtures/WebRuntimeCommand.php'),
        );
        foreach (['docker', 'tar'] as $binary) {
            file_put_contents($this->directory . '/' . $binary, $source);
            chmod($this->directory . '/' . $binary, 0700);
        }
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    /**
     * @return array{int,string,list<array{binary:string,arguments:list<string>,input:string}>}
     */
    private function runWorkflow(string $name, string $mode = 'success', bool $baked = false): array
    {
        $environment = getenv();
        $environment['PATH'] = $this->directory . ':' . $environment['PATH'];
        $environment['BAANDER_TEST_ARTIFACTS'] = $this->directory . '/artifacts';
        $environment['WEB_RUNTIME_CALLS'] = $this->directory . '/calls';
        $environment['WEB_RUNTIME_MODE'] = $mode;
        $environment['WEB_RUNTIME_ROOT'] = dirname(__DIR__, 3);
        $environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] = $baked ? '1' : '0';
        unset($environment['DOCKER_HOST'], $environment['DOCKER_CONTEXT'], $environment['BAANDER_TEST_DOCKER_ENDPOINT']);
        $result = (new ProcessRunner())->run(new ProcessSpec(
            $name === 'capacity'
                ? ['php', $this->directory . '/capacity.php', dirname(__DIR__, 3)]
                : ['bash', dirname(__DIR__, 2) . '/test-' . $name . '-runtime-container.sh'],
            cwd: dirname(__DIR__, 3),
            environment: $environment,
            timeout: 80,
        ));
        $calls = file($this->directory . '/calls', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            |> (static fn(array $lines): array => array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
        return [$result->exitCode, $result->stdout . $result->stderr, $calls];
    }

    /**
     * @param list<array{binary:string,arguments:list<string>,input:string}> $calls
     * @return list<list<string>>
     */
    private static function dockerCalls(array $calls): array
    {
        return $calls
            |> (static fn(array $calls): array => array_filter(
                $calls,
                static fn(array $call): bool => $call['binary'] === 'docker' && $call['arguments'] !== ['context', 'show'],
            ))
            |> (static fn(array $calls): array => array_map(static fn(array $call): array => array_slice($call['arguments'], 2), $calls))
            |> array_values(...);
    }

    public function testSecurityRunsEveryOutagePhaseAndExcludesCheckoutSecrets(): void
    {
        [$status, $output, $calls] = $this->runWorkflow('security');
        self::assertSame(0, $status, $output);
        $commands = self::dockerCalls($calls);
        $phases = [];
        foreach ($commands as $command) {
            $index = array_search('tests/Fixtures/websocket-runtime.php', $command, true);
            if ($index !== false) {
                $phases[] = $command[$index + 1];
            }
        }
        self::assertSame(
            ['normal', 'prepare-outage', 'outage', 'recovered', 'prepare-outage', 'outage', 'recovered', 'logs'],
            $phases,
        );
        $tar = array_values(array_filter($calls, static fn(array $call): bool => $call['binary'] === 'tar'))[0]['arguments'];
        self::assertContains('--exclude=config/secrets/oauth', $tar);
        self::assertContains('--exclude=config/secrets/*.decrypt.private.php', $tar);
        self::assertContains('packages', $tar);
        self::assertTrue(array_any($commands, static fn(array $command): bool => $command[0] === 'rm'));
        self::assertTrue(array_any($commands, static fn(array $command): bool => array_slice($command, 0, 3) === ['stop', '-t', '1']));
        self::assertTrue(array_any($commands, static fn(array $command): bool => $command[0] === 'pause'));
        self::assertTrue(array_any($commands, static fn(array $command): bool => $command[0] === 'unpause'));
    }

    public function testBakedImageSkipsArchiveAndStillRunsSecurityChecks(): void
    {
        [$status, $output, $calls] = $this->runWorkflow('security', baked: true);
        self::assertSame(0, $status, $output);
        self::assertFalse(array_any($calls, static fn(array $call): bool => $call['binary'] === 'tar'));
        self::assertFalse(array_any(self::dockerCalls($calls), static fn(array $call): bool => $call[0] === 'cp'));
        self::assertTrue(array_any(self::dockerCalls($calls), static fn(array $call): bool => in_array('/opt/baander', $call, true)));
    }

    public function testCommandFailureRetainsExitAndDiagnosticsDespiteCleanupFailure(): void
    {
        [$status, $output, $calls] = $this->runWorkflow('security', 'fixture-failure');
        self::assertSame(29, $status, $output);
        self::assertStringContainsString('security-characterization-failure', $output);
        self::assertStringContainsString('owned-serve-diagnostic', $output);
        $commands = self::dockerCalls($calls);
        self::assertTrue(array_any($commands, static fn(array $command): bool => array_slice($command, 0, 2) === ['network', 'rm']));
    }

    public function testCanaryFailsAndCleansOwnedResources(): void
    {
        [$status, $output] = $this->runWorkflow('security', 'canary');
        self::assertSame(1, $status);
        self::assertStringContainsString('A malformed request credential reached runtime logs.', $output);
    }

    public function testCleanupFailureChangesSuccessfulExit(): void
    {
        [$status, $output] = $this->runWorkflow('security', 'cleanup-failure', baked: true);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('Retry receipt:', $output);
        $receipt = $this->directory . '/artifacts/resources.json';
        self::assertFileExists($receipt);
        $ownership = json_decode(file_get_contents($receipt), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(4, $ownership['resources']);
        self::assertSame('unix:///web-fixture.sock', $ownership['target']['endpoint']);
    }

    public function testEarlyFailureStillAttemptsOwnedCleanupAndPreservesStatus(): void
    {
        [$status, $output, $calls] = $this->runWorkflow('web', 'early-failure');
        self::assertSame(37, $status, $output);
        $commands = self::dockerCalls($calls);
        self::assertTrue(array_any($commands, static fn(array $command): bool => array_slice($command, 0, 2) === ['network', 'inspect']));
        self::assertFalse(array_any($commands, static fn(array $command): bool => $command[0] === 'rm'));
    }

    public function testSafariMismatchPrintsDiffAndStopsBeforeOutage(): void
    {
        [$status, $output, $calls] = $this->runWorkflow('web', 'safari-mismatch');
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('wrong-baseline', $output);
        self::assertStringContainsString('differ from the baseline', $output);
        $tar = array_values(array_filter($calls, static fn(array $call): bool => $call['binary'] === 'tar'))[0]['arguments'];
        self::assertContains('scripts/web-runtime', $tar);
        self::assertFalse(array_any($tar, static fn(string $argument): bool => str_starts_with($argument, '--exclude=')));
        self::assertFalse(array_any(self::dockerCalls($calls), static fn(array $call): bool => $call[0] === 'stop'));
        $passwords = array_values(array_filter(
            array_column($calls, 'input'),
            static fn(string $input): bool => str_starts_with($input, 'Web-runtime-test-only-'),
        ));
        self::assertSame(["Web-runtime-test-only-1\n", "Web-runtime-test-only-2\n", "Web-runtime-test-only-3\n"], $passwords);
    }

    public function testWebRunsReloadAndBothOutageAlertChecks(): void
    {
        [$status, $output, $calls] = $this->runWorkflow('web', baked: true);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Each administrator has one Redis alert', $output);
        $commands = self::dockerCalls($calls);
        $alerts = array_values(array_filter($commands, static fn(array $command): bool => in_array('alert', $command, true)));
        self::assertCount(2, $alerts);
        self::assertSame('wait', end($alerts[0]));
        self::assertNotSame('wait', end($alerts[1]));
        self::assertTrue(array_any($commands, static fn(array $call): bool => in_array('PATCH', $call, true)));
        $phases = [];
        foreach ($commands as $command) {
            $index = array_search('scripts/web-runtime/redis-outage.php', $command, true);
            if ($index !== false) {
                $phases[] = $command[$index + 1];
            }
        }
        self::assertSame(['login', 'outage', 'recovered'], $phases);
    }

    public function testCapacityPublishesOnlyAnOwnedIngressWhileApplicationAndDependenciesStayInternal(): void
    {
        $source = file_get_contents($this->directory . '/docker');
        $handler = <<<'PHP'
if ($arguments[0] === 'network' && ($arguments[1] ?? '') === 'connect') {
    file_put_contents(getenv('WEB_RUNTIME_CALLS') . '.front-attached', 'yes');
    exit(0);
}
if ($arguments[0] === 'inspect') {
    $name = end($arguments);
    $instance = str_ends_with($name, '-ingress') ? substr($name, 0, -8) : substr($name, 0, -4);
    $published = is_file(getenv('WEB_RUNTIME_CALLS') . '.front-attached');
    echo json_encode([[
        'NetworkSettings' => [
            'Ports' => ['9443/tcp' => $published ? [['HostIp' => '127.0.0.1', 'HostPort' => '32796']] : null],
            'Networks' => [$instance => ['IPAddress' => '172.31.0.3']],
        ],
        'HostConfig' => ['PortBindings' => ['9443/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => '']]]],
    ]]);
    exit(0);
}
PHP;
        $boundary = '\Baander\Tooling\Tests\Fixtures\WebDockerState::handle($arguments, getenv(\'WEB_RUNTIME_CALLS\'));';
        file_put_contents($this->directory . '/docker', str_replace($boundary, $handler . "\n" . $boundary, $source));
        file_put_contents($this->directory . '/capacity.php', <<<'PHP'
<?php
require $_SERVER['argv'][1] . '/vendor/autoload.php';
try {
    exit((new Baander\Tooling\Fixtures\Web\RuntimeWorkflow($_SERVER['argv'][1], capacity: true))->execute(
        static function (Baander\Tooling\Fixtures\Web\RuntimeWorkflow $web): void {
            echo 'Capacity prepared: ', $web->capacityOrigin(), "\n";
        },
    ));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage());
    exit(1);
}
PHP);
        [$status, $output, $calls] = $this->runWorkflow('capacity', 'capacity', baked: true);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Capacity prepared: https://127.0.0.1:32796', $output);
        $commands = self::dockerCalls($calls);
        $networks = array_values(array_filter($commands, static fn(array $command): bool => array_slice($command, 0, 2) === ['network', 'create']));
        self::assertCount(2, $networks);
        self::assertContains('--internal', $networks[0]);
        self::assertNotContains('--internal', $networks[1]);
        $internal = $networks[0][array_key_last($networks[0])];
        $connections = array_values(array_filter($commands, static fn(array $command): bool => array_slice($command, 0, 2) === ['network', 'connect']));
        self::assertCount(1, $connections);
        self::assertStringEndsWith('-ingress', $connections[0][3]);
        self::assertSame($networks[1][array_key_last($networks[1])], $connections[0][2]);
        foreach ($commands as $command) {
            if ($command[0] !== 'create') {
                continue;
            }
            $name = $command[array_search('--name', $command, true) + 1];
            self::assertSame($internal, $command[array_search('--network', $command, true) + 1]);
            if (str_ends_with($name, '-ingress')) {
                self::assertContains('127.0.0.1::9443', $command);
            } else {
                self::assertNotContains('--publish', $command);
            }
        }
        self::assertTrue(array_any($commands, static fn(array $command): bool => $command[0] === 'exec' && in_array('TRUSTED_PROXIES=172.31.0.3', $command, true)));
        self::assertLessThan(array_search(['start', $connections[0][3]], $commands, true), array_search($connections[0], $commands, true));
        self::assertFileDoesNotExist($this->directory . '/artifacts/resources.json');
    }
}
