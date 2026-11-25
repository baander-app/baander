<?php

declare(strict_types=1);

use App\Kernel;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Health\HealthAlertTable;
use App\Shared\Infrastructure\Health\HealthStatus;
use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Tests\Support\FinalSchemaBootstrap;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/FinalSchemaBootstrap.php';
require_once __DIR__ . '/Messaging/MessageCodecFactory.php';
new Dotenv()->bootEnv(dirname(__DIR__, 2) . '/.env');

$mode = $argv[1] ?? '';
if (in_array($mode, ['ready', 'busy', 'unhealthy', 'stopped'], true)) {
    // A fresh reader, as the web container is: the key itself must carry the verdict.
    $health = new MessengerWorkerHealth(
        new RedisClientFactory((string) getenv('REDIS_URL')),
        new NativeClock(),
        new HealthAlertTable(),
    );
    $result = $health->check();
    exit(match ($mode) {
        'ready' => $result->isHealthy(),
        'busy' => $result->isHealthy() && ($result->details['phase'] ?? null) === 'busy',
        'unhealthy' => $result->status === HealthStatus::Unhealthy,
        // A fresh 'stopped' heartbeat reads not available: a replacement may still report in.
        'stopped' => $result->status === HealthStatus::NotAvailable
            && ($result->details['phase'] ?? null) === 'stopped',
    } ? 0 : 1);
}
if ($mode === 'consumer-pid') {
    // The heartbeat carries no PID, so the consumer is found by its command line.
    $pids = [];
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
        $arguments = explode("\0", (string) @file_get_contents($file));
        if (in_array('messenger:consume', $arguments, true)) {
            $pids[] = (int) (dirname($file) |> basename(...));
        }
    }
    if (count($pids) !== 1) {
        fwrite(STDERR, sprintf("Expected one Messenger consumer, found %d.\n", count($pids)));
        exit(1);
    }
    echo $pids[0];
    exit(0);
}

$kernel = new Kernel('prod', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();

if ($mode === 'prepare') {
    FinalSchemaBootstrap::bootstrap($db);
} elseif ($mode === 'hold-lock') {
    $db->beginTransaction();
    try {
        $db->executeStatement("SET LOCAL lock_timeout = '2s'");
        $db->executeStatement('LOCK TABLE domain_event_outbox IN ACCESS EXCLUSIVE MODE');
        touch('/tmp/baander-worker-test-lock-held');
        // Long enough to outlast the busy window after the kill.
        for ($attempt = 0; $attempt < 1800; ++$attempt) {
            if (is_file('/tmp/baander-worker-test-release-lock')) {
                break;
            }
            usleep(100_000);
        }
    } finally {
        $db->rollBack();
    }
} elseif ($mode === 'send') {
    $transport = new RedisTransport(
        Connection::fromDsn(getenv('MESSENGER_TRANSPORT_DSN'), ['group' => 'baander']),
        MessageCodecFactory::signingSerializer(),
    );
    // The job ID that JobMonitoringMiddleware assigns at dispatch.
    $transport->send(new Envelope(new RelayOutboxCommand(), [
        new JobIdStamp(new PublicId()),
    ]));
} elseif ($mode === 'handled') {
    $count = (int) $db->fetchOne("SELECT COUNT(*) FROM job_monitors WHERE status = 'finished'");
    exit($count >= (int) ($argv[2] ?? 1) ? 0 : 1);
} elseif ($mode === 'one-row-per-job') {
    // A redelivery restarts its job's row, so the killed attempt leaves no running row behind.
    $rows = $db->fetchAssociative("SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'finished' AND finished_at >= started_at) AS finished, max(attempt) AS attempts FROM job_monitors");
    $expected = (int) ($argv[2] ?? 1);
    exit((int) $rows['total'] === $expected && (int) $rows['finished'] === $expected && (int) $rows['attempts'] === 2 ? 0 : 1);
} else {
    throw new RuntimeException('Unknown runtime-test mode.');
}
