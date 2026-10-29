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
        foreach (['docker', 'tar'] as $binary) {
            copy(__DIR__ . '/Fixtures/WebRuntimeCommand.php', $this->directory . '/' . $binary);
            chmod($this->directory . '/' . $binary, 0700);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
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
        $environment['WEB_RUNTIME_CALLS'] = $this->directory . '/calls';
        $environment['WEB_RUNTIME_MODE'] = $mode;
        $environment['WEB_RUNTIME_ROOT'] = dirname(__DIR__, 3);
        $environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] = $baked ? '1' : '0';
        unset($environment['DOCKER_HOST'], $environment['DOCKER_CONTEXT'], $environment['BAANDER_TEST_DOCKER_ENDPOINT']);
        $result = (new ProcessRunner())->run(new ProcessSpec(
            ['bash', dirname(__DIR__, 2) . '/test-' . $name . '-runtime-container.sh'],
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
        $id = $commands[count($commands) - 1][2];
        self::assertSame(
            ['rm', '-f', '-v', $id . '-app', $id . '-redis', $id . '-postgres'],
            $commands[count($commands) - 2],
        );
        self::assertSame(['network', 'rm'], array_slice($commands[count($commands) - 1], 0, 2));
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
        self::assertTrue(array_any(self::dockerCalls($calls), static fn(array $call): bool => in_array('/var/www/html', $call, true)));
    }

    public function testCommandFailureRetainsExitAndDiagnosticsDespiteCleanupFailure(): void
    {
        [$status, $output, $calls] = $this->runWorkflow('security', 'fixture-failure');
        self::assertSame(29, $status, $output);
        self::assertStringContainsString('security-characterization-failure', $output);
        self::assertStringContainsString('owned-serve-diagnostic', $output);
        $commands = self::dockerCalls($calls);
        self::assertSame(['network', 'rm'], array_slice($commands[count($commands) - 1], 0, 2));
    }

    public function testCanaryFailsAndCleansOwnedResources(): void
    {
        [$status, $output] = $this->runWorkflow('security', 'canary');
        self::assertSame(1, $status);
        self::assertStringContainsString('A malformed request credential reached runtime logs.', $output);
    }

    public function testCleanupFailureDoesNotChangeSuccessfulExit(): void
    {
        [$status, $output] = $this->runWorkflow('security', 'cleanup-failure', baked: true);
        self::assertSame(0, $status, $output);
    }

    public function testEarlyFailureStillAttemptsOwnedCleanupAndPreservesStatus(): void
    {
        [$status, $output, $calls] = $this->runWorkflow('web', 'early-failure');
        self::assertSame(37, $status, $output);
        $commands = self::dockerCalls($calls);
        $id = $commands[count($commands) - 1][2];
        self::assertSame(
            ['rm', '-f', '-v', $id . '-app', $id . '-mailpit', $id . '-redis', $id . '-postgres'],
            $commands[count($commands) - 2],
        );
        self::assertSame(['network', 'rm'], array_slice($commands[count($commands) - 1], 0, 2));
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
}
