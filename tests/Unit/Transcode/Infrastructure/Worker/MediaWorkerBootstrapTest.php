<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Worker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaWorkerBootstrapTest extends TestCase
{
    /** @param list<string> $arguments */
    #[DataProvider('privateEntrypoints')]
    public function testInvalidInheritedKernelConfigurationFailsWithoutLoadingDotenvOrLeakingCredentials(string $script, array $arguments): void
    {
        $environment = [
            'BAANDER_WORKER_NAMESPACE' => 'test.baander.app',
            'BAANDER_WORKER_BOOT_ID' => str_repeat('a', 32),
            'BAANDER_WORKER_ID' => 'media',
            'BAANDER_WORKER_GENERATION' => '1',
            'BAANDER_WORKER_LEASE_EPOCH' => '1',
            'DATABASE_URL' => 'postgresql://credential:private-secret@baander.app/database',
            // The repository Dotenv files must not fill this missing inherited setting.
            'APP_DEBUG' => '0',
        ];
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 5) . '/bin/' . $script, ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $output);
        self::assertStringContainsString('deployment reconciliation is required', $error);
        self::assertStringNotContainsString('private-secret', $error);
        self::assertStringNotContainsString('credential', $error);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function privateEntrypoints(): iterable
    {
        yield 'manager' => ['worker-media.php', []];
        yield 'audio job' => ['worker-media-job.php', ['audio', str_repeat('a', 64)]];
        yield 'video job' => ['worker-media-job.php', ['video', '019a9e53-8192-72dd-a50a-9f15553cecaf']];
    }
}
