<?php

declare(strict_types=1);

use App\Tests\Fixtures\Messaging\MessageCodecFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/Messaging/MessageCodecFactory.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$mode = $argv[1] ?? '';
if ($mode === 'ready' || $mode === 'busy') {
    $health = new App\Shared\Infrastructure\Health\MessengerWorkerHealth(new Symfony\Component\Clock\NativeClock());
    $result = $health->check();
    exit($result->isHealthy() && ($mode === 'ready' || ($result->details['phase'] ?? null) === 'busy') ? 0 : 1);
}

$kernel = new App\Kernel('prod', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();

if ($mode === 'prepare') {
    (new Doctrine\ORM\Tools\SchemaTool($em))->createSchema([$em->getClassMetadata(App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity::class)]);
    $db->executeStatement('CREATE TABLE domain_event_outbox (
        id BIGSERIAL PRIMARY KEY, event_class TEXT NOT NULL, event_name TEXT NOT NULL,
        payload JSONB NOT NULL, created_at TIMESTAMPTZ NOT NULL, relayed_at TIMESTAMPTZ,
        attempts INTEGER NOT NULL DEFAULT 0, next_attempt_at TIMESTAMPTZ, dead_lettered_at TIMESTAMPTZ,
        lease_token TEXT, lease_until TIMESTAMPTZ
    )');
    require_once dirname(__DIR__, 2) . '/migrations/Version20261002120000.php';
    $migration = new DoctrineMigrations\Version20261002120000($db, new Psr\Log\NullLogger());
    $migration->up(new Doctrine\DBAL\Schema\Schema());
    foreach ($migration->getSql() as $query) {
        $db->executeStatement($query->getStatement());
    }
} elseif ($mode === 'hold-lock') {
    $db->beginTransaction();
    try {
        $db->executeStatement("SET LOCAL lock_timeout = '2s'");
        $db->executeStatement('LOCK TABLE domain_event_outbox IN ACCESS EXCLUSIVE MODE');
        touch('/tmp/baander-worker-test-lock-held');
        for ($attempt = 0; $attempt < 300; ++$attempt) {
            if (is_file('/tmp/baander-worker-test-release-lock')) {
                break;
            }
            usleep(100_000);
        }
    } finally {
        $db->rollBack();
    }
} elseif ($mode === 'send') {
    $transport = new Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport(
        Symfony\Component\Messenger\Bridge\Redis\Transport\Connection::fromDsn(getenv('MESSENGER_TRANSPORT_DSN'), ['group' => 'baander']),
        new App\Shared\Infrastructure\Messenger\JsonTransportSerializer(MessageCodecFactory::create()),
    );
    // The job ID that JobMonitoringMiddleware assigns at dispatch.
    $transport->send(new Symfony\Component\Messenger\Envelope(new App\Shared\Domain\Event\Outbox\RelayOutboxCommand(), [
        new App\Shared\Infrastructure\Messenger\JobIdStamp(new App\Shared\Domain\Model\PublicId()),
    ]));
} elseif ($mode === 'handled') {
    $count = (int) $db->fetchOne("SELECT COUNT(*) FROM job_monitors WHERE status = 'finished'");
    exit($count >= (int) ($argv[2] ?? 1) ? 0 : 1);
} elseif ($mode === 'one-row-per-job') {
    // A redelivery restarts its job's row, so the killed attempt leaves no running row behind.
    $rows = $db->fetchAssociative("SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'finished' AND finished_at >= started_at) AS finished, max(attempt) AS attempts FROM job_monitors");
    exit((int) $rows['total'] === (int) $argv[2] && (int) $rows['finished'] === (int) $argv[2] && (int) $rows['attempts'] === 2 ? 0 : 1);
} else {
    throw new RuntimeException('Unknown runtime-test mode.');
}
