<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TwoCommandProcessGuardTest extends TestCase
{
    /** @param list<string> $arguments */
    #[DataProvider('processArguments')]
    public function testIdleExecutorDetectionUsesProcessIdentityRatherThanArgumentSubstrings(array $arguments, bool $forbidden, string $error = 'A background executor exists in the web container.'): void
    {
        $directory = sys_get_temp_dir() . '/baander-process-guard-' . bin2hex(random_bytes(8));
        mkdir($directory . '/2', 0755, true);
        $fields = array_fill(0, 22, '0');
        $fields[0] = 'S';
        $fields[1] = '1';
        $fields[19] = '42';
        $fields[21] = '1';
        file_put_contents($directory . '/2/stat', '2 (idle process) ' . implode(' ', $fields));
        file_put_contents($directory . '/2/cmdline', implode("\0", $arguments) . "\0");
        try {
            $process = new Process([PHP_BINARY, dirname(__DIR__, 4) . '/Fixtures/TwoCommand/processes.php', 'web', $directory]);
            $process->setTimeout(5.0);
            $process->run();
            if ($forbidden) {
                self::assertFalse($process->isSuccessful(), 'Idle background executor passed web ownership: ' . json_encode($arguments));
                self::assertStringContainsString($error, $process->getErrorOutput());
            } else {
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $snapshot = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($arguments, $snapshot['processes'][0]['arguments']);
                self::assertSame([], $snapshot['encoders']);
            }
        } finally {
            unlink($directory . '/2/stat');
            unlink($directory . '/2/cmdline');
            rmdir($directory . '/2');
            rmdir($directory);
        }
    }

    /** @return iterable<string, array{list<string>, bool, 2?: string}> */
    public static function processArguments(): iterable
    {
        yield 'FFmpeg encoder' => [['/usr/bin/ffmpeg', '-threads', '1'], true, 'An encoder or probe exists in the web container.'];
        yield 'FFprobe' => [['/usr/bin/ffprobe', '-show_streams'], true, 'An encoder or probe exists in the web container.'];
        yield 'idle CPU worker' => [['cpu-pool-worker-0'], true];
        yield 'another CPU worker' => [['cpu-pool-worker-27'], true];
        yield 'actual relay command' => [['/usr/local/bin/php', '-d', 'memory_limit=256M', '/app/bin/console', 'app:outbox:consume', '--time-limit=86400'], true];
        yield 'actual consumer command' => [['/usr/local/bin/php', '-d', 'memory_limit=256M', '/app/bin/console', 'messenger:consume', 'async'], true];
        yield 'media manager' => [['/usr/local/bin/php', '-d', 'memory_limit=256M', '/app/bin/worker-media.php'], true];
        yield 'media child' => [['/usr/local/bin/php', '/app/bin/worker-media-job.php', 'video', 'job'], true];
        yield 'lease agent' => [['/usr/local/bin/php', '/app/bin/worker-lease-agent.php'], true];
        yield 'deployment supervisor' => [['/usr/local/bin/php', '/app/bin/worker-deployment.php'], true];
        yield 'relay with Symfony global options' => [['/usr/local/bin/php8.5', '/app/bin/console', '--env', 'prod', 'app:outbox:consume'], true];
        yield 'scheduler' => [['/usr/local/bin/php', '-d', 'memory_limit=256M', '/app/bin/worker-scheduler.php'], true];
        yield 'worker supervisor' => [['/usr/local/bin/php', '/app/bin/console', 'app:worker'], true];
        yield 'ordinary web command' => [['/usr/local/bin/php', '/app/bin/console', 'app:serve'], false];
        yield 'harmless command operands' => [['/usr/local/bin/php', '/app/bin/console', 'debug:container', 'messenger:consume', 'app:outbox:consume'], false];
        yield 'harmless configuration option' => [['/usr/local/bin/php', '-d', 'error_log=/tmp/worker-media.log', '/app/bin/console', 'app:serve'], false];
        yield 'harmless script path' => [['/usr/local/bin/php', '/app/worker-media/report.php'], false];
        yield 'harmless title substring' => [['idle-cpu-pool-worker-0-monitor'], false];
    }
}
