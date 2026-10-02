<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
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
        new App\Shared\Infrastructure\Messenger\JsonTransportSerializer(new App\Shared\Infrastructure\Messaging\JsonMessageCodec()),
    );
    $transport->send(new Symfony\Component\Messenger\Envelope(new App\Shared\Domain\Event\Outbox\RelayOutboxCommand()));
} elseif ($mode === 'handled') {
    $count = (int) $db->fetchOne("SELECT COUNT(*) FROM job_monitors WHERE status = 'finished'");
    exit($count >= (int) ($argv[2] ?? 1) ? 0 : 1);
} else {
    throw new RuntimeException('Unknown runtime-test mode.');
}
