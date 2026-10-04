<?php

declare(strict_types=1);

// Isolated integration child only. No production entrypoint or implicit scheduler boot.
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Scheduler\Application\Service\SchedulerOccurrenceRelay;
use App\Scheduler\Application\Service\SchedulerRecoveryPoller;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceDispatchStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceMaterializer;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerRecoveryScheduleStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerWorkerAuthority;
use App\Scheduler\Infrastructure\Messenger\MessengerSchedulerOccurrencePublisher;
use App\Scheduler\Infrastructure\Process\SchedulerWorkerRunner;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once __DIR__ . '/../Messaging/MessageCodecFactory.php';
try {
    $database = getenv('DATABASE_URL');
    $redis = getenv('MESSENGER_TEST_REDIS_DSN');
    $stream = getenv('BAANDER_TEST_SCHEDULER_STREAM');
    if (!$database || !$redis || !$stream) {
        throw new RuntimeException('Missing isolated fixture environment.');
    }
    $barrierDirectory = getenv('BAANDER_TEST_SCHEDULER_BARRIER_DIRECTORY');
    $wait = null;
    if ($barrierDirectory !== false) {
        if (!str_starts_with($barrierDirectory, '/') || strlen($barrierDirectory) > 4096 || str_contains($barrierDirectory, "\0")
            || !is_dir($barrierDirectory) || !is_writable($barrierDirectory)) {
            throw new RuntimeException('Invalid isolated fixture barrier directory.');
        }
        $reached = false;
        $wait = static function (float $seconds) use ($barrierDirectory, &$reached): void {
            if (!is_finite($seconds) || $seconds <= 0 || $seconds > 0.1) {
                throw new RuntimeException('Invalid isolated fixture wait interval.');
            }
            if (!$reached) {
                $reached = true;
                if (!touch($barrierDirectory . '/waiting')) {
                    throw new RuntimeException('Isolated fixture barrier could not be marked.');
                }
                $deadline = hrtime(true) / 1e9 + 5.0;
                while (!is_file($barrierDirectory . '/release')) {
                    if (hrtime(true) / 1e9 >= $deadline) {
                        throw new RuntimeException('Isolated fixture barrier timed out.');
                    }
                    usleep(10000);
                }
            }
            usleep((int) ceil($seconds * 1e6));
        };
    }
    $transport = new RedisTransport(Connection::fromDsn($redis, ['stream' => $stream, 'group' => 'test', 'consumer' => 'test']), new JsonTransportSerializer(MessageCodecFactory::create()));
    $runner = new SchedulerWorkerRunner(
        new SchedulerRecoveryPoller(DoctrineSchedulerRecoveryScheduleStore::fromDsn($database), DoctrineSchedulerOccurrenceMaterializer::fromDsn($database)),
        new SchedulerOccurrenceRelay(DoctrineSchedulerOccurrenceDispatchStore::fromDsn($database), new MessengerSchedulerOccurrencePublisher($transport)),
        DoctrineSchedulerWorkerAuthority::fromDsn($database), new NullLogger(), wait: $wait,
    );
    echo "ready\n";
    $runner->runUntilSignalled();
    echo "stopped\n";
} catch (Throwable) {
    // Never expose credentials or payloads in child diagnostics.
    fwrite(STDERR, "scheduler_worker_failed\n");
    exit(1);
}
