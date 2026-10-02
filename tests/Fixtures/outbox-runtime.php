<?php

declare(strict_types=1);

use App\Auth\Domain\Event\UserRegistered;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Notification\Infrastructure\Doctrine\Entity\NotificationEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new App\Kernel('prod', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();
$mode = $argv[1] ?? '';

$assertCount = static function (string $sql, int $expected) use ($db): void {
    $actual = (int) $db->fetchOne($sql);
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf('Expected %d, got %d: %s', $expected, $actual, $sql));
    }
};

if ($mode === 'prepare') {
    $db->executeStatement('CREATE EXTENSION IF NOT EXISTS citext');
    (new SchemaTool($em))->createSchema(array_map($em->getClassMetadata(...), [
        UserEntity::class, NotificationEntity::class, JobMonitorEntity::class,
    ]));
    $db->executeStatement('CREATE TABLE domain_event_outbox (
        id BIGSERIAL PRIMARY KEY, event_class TEXT NOT NULL, event_name TEXT NOT NULL,
        payload JSONB NOT NULL, created_at TIMESTAMPTZ NOT NULL, relayed_at TIMESTAMPTZ,
        attempts INTEGER NOT NULL DEFAULT 0, next_attempt_at TIMESTAMPTZ, dead_lettered_at TIMESTAMPTZ,
        lease_token TEXT, lease_until TIMESTAMPTZ
    )');
    require_once dirname(__DIR__, 2) . '/migrations/Version20261002120000.php';
    $migration = new DoctrineMigrations\Version20261002120000($db, new NullLogger());
    $migration->up(new Schema());
    foreach ($migration->getSql() as $query) {
        $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
    }

    $user = new UserEntity(new PublicId(), 'Outbox User', 'outbox-user@example.com', 'unused-password', '');
    $user->markEmailAsVerified();
    $admin = new UserEntity(new PublicId(), 'Outbox Admin', 'outbox-admin@example.com', 'unused-password', '', roles: ['ROLE_USER', 'ROLE_ADMIN']);
    $admin->markEmailAsVerified();
    $em->persist($user);
    $em->persist($admin);
    $em->flush();

    $kernel->getContainer()->get('event_dispatcher')->dispatch(new UserRegistered(
        $user->getId(), $user->getPublicId(), Email::fromString($user->getEmail()), $user->getName(),
    ));
    $assertCount('SELECT COUNT(*) FROM domain_event_outbox', 1);
    $assertCount('SELECT COUNT(*) FROM notifications', 0);
    $assertCount('SELECT COUNT(*) FROM domain_event_outbox_receipt', 0);
    $assertCount('SELECT COUNT(*) FROM domain_event_outbox_delivery', 0);
    echo "Live event persisted without running notification consumers.\n";
} elseif ($mode === 'verify') {
    $assertCount('SELECT COUNT(*) FROM notifications', 2);
    $assertCount("SELECT COUNT(*) FROM notifications WHERE event_type = 'user.registered'", 1);
    $assertCount("SELECT COUNT(*) FROM notifications WHERE event_type = 'admin.user_registered'", 1);
    $assertCount("SELECT COUNT(*) FROM domain_event_outbox_receipt WHERE consumer = 'notifications.v1'", 1);
    $assertCount('SELECT COUNT(*) FROM domain_event_outbox_delivery', 3);
    foreach (['email', 'push', 'webhook'] as $channel) {
        $assertCount("SELECT COUNT(*) FROM domain_event_outbox_delivery WHERE channel = '$channel' AND relayed_at IS NOT NULL", 1);
    }
    $assertCount('SELECT COUNT(*) FROM domain_event_outbox WHERE relayed_at IS NOT NULL', 1);
    $assertCount('SELECT COUNT(*) FROM domain_event_outbox WHERE attempts > 0 OR dead_lettered_at IS NOT NULL', 0);
    $assertCount('SELECT COUNT(*) FROM domain_event_outbox_delivery WHERE attempts > 0 OR dead_lettered_at IS NOT NULL', 0);
    $redis = new Redis();
    $redis->connect('redis', 6379);
    $redis->auth(['default', getenv('REDIS_PASSWORD')]);
    $count = $redis->xLen('messages');
    if ($count !== 3) {
        throw new RuntimeException(sprintf('Expected three durable Redis handoffs, got %d.', $count));
    }
    echo "User/admin notifications, receipt, channel intents, and Redis handoffs verified.\n";
} elseif ($mode === 'reset-ack') {
    $db->executeStatement('UPDATE domain_event_outbox SET relayed_at = NULL');
    echo "Simulated a lost event acknowledgement.\n";
} else {
    throw new RuntimeException('Unknown outbox runtime-test mode.');
}

$kernel->shutdown();
