<?php

declare(strict_types=1);

use App\Auth\Domain\Event\UserRegistered;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/FinalSchemaBootstrap.php';
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
    App\Tests\Support\FinalSchemaBootstrap::bootstrap($db);

    $user = new UserEntity(new PublicId(), 'Outbox User', 'outbox-user@baander.app', 'unused-password', '');
    $user->markEmailAsVerified();
    $admin = new UserEntity(new PublicId(), 'Outbox Admin', 'outbox-admin@baander.app', 'unused-password', '', roles: ['ROLE_USER', 'ROLE_ADMIN']);
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
