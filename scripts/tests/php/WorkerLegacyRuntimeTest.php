<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Runner;
use PHPUnit\Framework\TestCase;

final class WorkerLegacyRuntimeTest extends TestCase
{
    public function testInterruptionCollectsSupervisorLogsBeforeRemovingOwnedResources(): void
    {
        $directory = sys_get_temp_dir() . '/baander-legacy-runtime-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        copy(__DIR__ . '/Fixtures/worker-legacy-runtime-docker.php', $directory . '/bin/docker');
        chmod($directory . '/bin/docker', 0700);
        $runner = new Runner();
        try {
            $result = $runner->run(
                [PHP_BINARY, dirname(__DIR__, 2) . '/test-worker-runtime-container.php'],
                env: array_replace(getenv(), [
                    'PATH' => $directory . '/bin:' . getenv('PATH'),
                    'DOCKER_CONTEXT' => 'fixture',
                    'BAANDER_TEST_CHECKOUT_IN_IMAGE' => '1',
                    'BAANDER_TEST_ARTIFACTS' => $directory . '/artifacts',
                    'WORKER_LEGACY_COMMANDS' => $directory . '/commands.jsonl',
                ]),
                timeout: 25,
                check: false,
            );
            self::assertSame(143, $result->exitCode, $result->stdout . $result->stderr);
            self::assertLessThan(20, $result->duration);
            self::assertFileExists($directory . '/artifacts/supervisor/supervisord.log');
            self::assertSame(
                "interrupted supervisor evidence\n",
                file_get_contents($directory . '/artifacts/supervisor/supervisord.log'),
            );
            $commands = file($directory . '/commands.jsonl', FILE_IGNORE_NEW_LINES)
                |> (static fn(array $lines): array => array_map(
                    static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                    $lines,
                ));
            $copy = array_find_key($commands, static fn(array $arguments): bool => $arguments[0] === 'cp');
            $remove = array_find_key($commands, static fn(array $arguments): bool => $arguments[0] === 'rm');
            self::assertNotNull($copy);
            self::assertNotNull($remove);
            self::assertLessThan($remove, $copy);
            $cleanup = json_decode(file_get_contents($directory . '/artifacts/cleanup.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($cleanup['cleanupVerified']);
        } finally {
            $runner->run(['rm', '-rf', '--', $directory]);
        }
    }
}
