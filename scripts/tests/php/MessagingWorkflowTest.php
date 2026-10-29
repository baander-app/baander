<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Fixtures\Messaging\Qualification;
use PHPUnit\Framework\TestCase;

final class MessagingWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-messaging-contract-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/bin', 0700, true);
        mkdir($this->root . '/vendor', 0700);
        mkdir($this->root . '/tests/Fixtures', 0700, true);
        file_put_contents($this->root . '/vendor/autoload.php', '<?php');
        file_put_contents($this->root . '/tests/Fixtures/postgres-extensions.sql', 'CREATE EXTENSION fixture;');
        file_put_contents($this->root . '/bin/docker', '#!' . PHP_BINARY . "\n" . file_get_contents(__DIR__ . '/Fixtures/messaging-docker.php'));
        file_put_contents(
            $this->root . '/bin/tar',
            '#!' . PHP_BINARY . "\n" . <<<'PHP_CODE'
        <?php

        file_put_contents(getenv('PROBE_ROOT') . '/archive-arguments', json_encode(array_slice($_SERVER['argv'], 1)));
        file_put_contents($_SERVER['argv'][2], 'private archive');
        PHP_CODE,
        );
        chmod($this->root . '/bin/docker', 0700);
        chmod($this->root . '/bin/tar', 0700);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /** @param array<string,string> $environment */
    private function execute(string $mode, array $environment = [], float $seconds = 60): \Baander\Tooling\Process\ProcessResult
    {
        $code = <<<'PHP_CODE'
        require $argv[1];
        file_put_contents($argv[2] . '/runner-pid', (string) getmypid());
        if (getenv('PROBE_USE_MAIN') === '1') {
            try {
                exit(Baander\Tooling\Fixtures\Messaging\Qualification::main($argv[2], $argv[3]));
            } catch (Throwable $error) {
                fwrite(STDERR, Baander\Tooling\Fixtures\Runtime::failure($error));
                exit(Baander\Tooling\Fixtures\Runtime::status($error));
            }
        }
        $r = new Baander\Tooling\Fixtures\Runtime($argv[2], (float) $argv[4]);
        try {
            Baander\Tooling\Fixtures\Messaging\Qualification::run($r, $argv[3]);
        } catch (Throwable $error) {
            fwrite(STDERR, Baander\Tooling\Fixtures\Runtime::failure($error));
            exit(Baander\Tooling\Fixtures\Runtime::status($error));
        } finally {
            $r->close();
        }
        PHP_CODE;
        return new \Baander\Tooling\Process\ProcessRunner()->run(new \Baander\Tooling\Process\ProcessSpec(
            argv: [
                PHP_BINARY,
                '-r',
                $code,
                dirname(__DIR__, 2) . '/bootstrap.php',
                $this->root,
                $mode,
                (string) $seconds,
            ],
            environment: [
                ...getenv(),
                'PROBE_ROOT' => $this->root,
                'PATH' => $this->root . '/bin:' . getenv('PATH'),
                'BAANDER_TEST_ARTIFACTS' => $this->root . '/artifacts',
                ...$environment,
            ],
            timeout: 75,
        ));
    }

    /** @return list<list<string>> */
    private function recorded(): array
    {
        return file($this->root . '/commands', FILE_IGNORE_NEW_LINES)
            |> (static fn(array $lines): array => array_map(
                static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                $lines,
            ));
    }

    /** @return array<string,mixed> */
    private function json(string $relative): array
    {
        $contents = file_get_contents($this->root . '/' . $relative);
        self::assertIsString($contents);
        return $contents
            |> (static fn(string $text): array => json_decode($text, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testOutboxUsesAuthenticatedServicesExtensionsReplayAndVerifiedCleanup(): void
    {
        $result = $this->execute('outbox');
        self::assertSame(0, $result->exitCode, $result->stderr);
        $calls = $this->recorded();
        self::assertTrue(array_any($calls, static fn(array $args): bool => in_array('--internal', $args, true)));
        self::assertTrue(array_any($calls, static fn(array $args): bool => in_array('REDISCLI_AUTH=test-only', $args, true)));
        self::assertSame(
            2,
            count(array_filter($calls, static fn(array $args): bool => in_array('app:outbox:consume', $args, true))),
        );
        self::assertSame('CREATE EXTENSION fixture;', file_get_contents($this->root . '/sql'));
        self::assertTrue(array_any(
            $calls,
            static fn(array $args): bool => in_array('fixture-stage', $args, true)
                && !in_array('--user', $args, true),
        ));
        self::assertFalse(is_file($this->root . '/artifacts/checkout.tar'));
        self::assertFileDoesNotExist($this->root . '/artifacts/resources.json');
        self::assertSame(['containers' => [], 'networks' => []], $this->json('state'));
    }

    public function testProducerCanRunTheImageCheckoutWithoutArchivingHostSource(): void
    {
        $result = $this->execute('producer', ['BAANDER_TEST_CHECKOUT_IN_IMAGE' => '1', 'PROBE_APP_HOME' => '/srv/baander']);
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertFalse(is_file($this->root . '/archive-arguments'));
        self::assertTrue(array_any(
            $this->recorded(),
            static fn(array $args): bool => in_array('/srv/baander', $args, true)
                && in_array('tests/Fixtures/producer-runtime.php', $args, true),
        ));
    }

    public function testMessagingCopiesSourceAndRetainsFailureAcrossCleanupFailure(): void
    {
        $result = $this->execute('messaging', ['PROBE_WORK_FAIL' => '1', 'PROBE_CLEANUP_FAIL' => '1']);
        self::assertSame(7, $result->exitCode, $result->stderr);
        self::assertStringContainsString('fixture workload failed', $result->stderr);
        self::assertStringContainsString('cleanup failed', $result->stderr);
        self::assertFileExists($this->root . '/sql');
        self::assertSame('CREATE EXTENSION fixture;', file_get_contents($this->root . '/sql'));
        self::assertFileExists($this->root . '/archive-arguments');
        $calls = $this->recorded();
        $provision = array_find_key($calls, static fn(array $args): bool => in_array('psql', $args, true));
        $workload = array_find_key($calls, static fn(array $args): bool => in_array('--fail-on-skipped', $args, true));
        self::assertNotNull($provision);
        self::assertNotNull($workload);
        self::assertContains('messaging_test', $calls[$provision]);
        self::assertLessThan($workload, $provision);
        self::assertCount(1, $this->json('artifacts/resources.json')['resources']);
    }

    public function testWorkflowInterruptionRetainsItsSignalStatusAndCleansContainers(): void
    {
        $result = $this->execute(
            'producer',
            [
                'BAANDER_TEST_CHECKOUT_IN_IMAGE' => '1',
                'PROBE_WORK_INTERRUPT' => '1',
            ],
        );
        self::assertSame(143, $result->exitCode, $result->stderr);
        self::assertFileDoesNotExist($this->root . '/artifacts/resources.json');
        self::assertSame(['containers' => [], 'networks' => []], $this->json('state'));
    }

    public function testReadinessTimeoutStillRemovesOnlyRunOwnedResources(): void
    {
        $unrelated = [
            'containers' => ['unrelated-baander-app' => true],
            'networks' => ['unrelated-network' => true],
        ];
        file_put_contents($this->root . '/state', json_encode($unrelated, JSON_THROW_ON_ERROR));
        $result = $this->execute('messaging', ['PROBE_NOT_READY' => '1'], .5);
        self::assertSame(124, $result->exitCode, $result->stderr);
        self::assertFileDoesNotExist($this->root . '/artifacts/resources.json');
        self::assertSame($unrelated, $this->json('state'));
    }

    public function testWorkloadSelectionRetainsOutboxReplayAndStrictMessagingCases(): void
    {
        self::assertSame(
            [
                ['php', 'tests/Fixtures/outbox-runtime.php', 'prepare'],
                ['php', 'bin/console', 'app:outbox:consume', '--once', '--no-interaction'],
                ['php', 'tests/Fixtures/outbox-runtime.php', 'verify'],
                ['php', 'tests/Fixtures/outbox-runtime.php', 'reset-ack'],
                ['php', 'bin/console', 'app:outbox:consume', '--once', '--no-interaction'],
                ['php', 'tests/Fixtures/outbox-runtime.php', 'verify'],
            ],
            Qualification::commands('outbox'),
        );
        self::assertSame([['php', 'tests/Fixtures/producer-runtime.php']], Qualification::commands('producer'));
        $messaging = Qualification::commands('messaging')[0];
        self::assertContains('tests/Integration/MessengerAdmissionLifecycleTest.php', $messaging);
        self::assertContains('tests/Integration/WorkerLeaseAgentTest.php', $messaging);
        self::assertContains('--fail-on-phpunit-notice', $messaging);
        self::assertContains('--fail-on-skipped', $messaging);
    }

    public function testEntrypointUsesConfiguredFiniteMessagingBudget(): void
    {
        file_put_contents($this->root . '/dev.local.json', '{"tooling":{"budgets":{"messaging":0.05}}}');
        $result = $this->execute('messaging', ['PROBE_USE_MAIN' => '1', 'CI' => 'false']);
        self::assertSame(124, $result->exitCode, $result->stderr);
        self::assertLessThan(5, $result->duration);
        self::assertFileDoesNotExist($this->root . '/artifacts/resources.json');
    }
}
