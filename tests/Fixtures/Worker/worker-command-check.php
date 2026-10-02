<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$script, $mode, $namespace, $bootId] = $argv + [null, null, null, null];
if (!in_array($mode, ['ready', 'kill-consumer', 'reserved', 'expire', 'seed-outbox', 'verify-outbox', 'replay-outbox'], true)
    || !is_string($namespace) || !is_string($bootId)) {
    throw new RuntimeException('Invalid isolated worker check request.');
}
App\Shared\Infrastructure\Worker\DeploymentLease::validateIdentity($namespace, $bootId);
$url = getenv('DATABASE_URL');
if (!$url) {
    throw new RuntimeException('Disposable DATABASE_URL is required.');
}
$db = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
if ($mode === 'seed-outbox') {
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3) . '/.env');
    $kernel = new App\Kernel('prod', false);
    $kernel->boot();
    $em = $kernel->getContainer()->get('doctrine')->getManager();
    $user = new App\Auth\Infrastructure\Doctrine\Entity\UserEntity(new App\Shared\Domain\Model\PublicId(), 'Worker Command User', 'worker-command@baander.app', 'unused-password', '');
    $em->persist($user); // Unverified: CreateNotificationHandler does not enqueue email.
    foreach (['email', 'push', 'webhook'] as $channel) {
        $preference = new App\Notification\Infrastructure\Doctrine\Entity\NotificationPreferenceEntity(App\Shared\Domain\Model\Uuid::v7());
        $preference->setUser($user);
        $preference->setCategory('security');
        $preference->setChannel($channel);
        $preference->setEnabled(false);
        $em->persist($preference);
    }
    $em->flush();
    $kernel->getContainer()->get('event_dispatcher')->dispatch(new App\Auth\Domain\Event\UserRegistered($user->getId(), $user->getPublicId(), App\Shared\Domain\Model\Email::fromString($user->getEmail()), $user->getName()));
    if ((int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox') !== 1 || (int) $db->fetchOne('SELECT count(*) FROM notifications') !== 0 || (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox_delivery') !== 0 || (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox_receipt') !== 0) {
        throw new RuntimeException('Real capture must commit exactly one pending event without projecting it.');
    }
    $kernel->shutdown();
    echo "Real UserRegistered event captured; outbound preferences disabled.\n";
    exit(0);
}
if ($mode === 'verify-outbox' || $mode === 'replay-outbox') {
    $event = $db->fetchAssociative("SELECT id, relayed_at, attempts, dead_lettered_at FROM domain_event_outbox WHERE event_name = 'user.registered' AND payload->>'email' = :email", ['email' => 'worker-command@baander.app']);
    if ($event === false || $event['relayed_at'] === null || (int) $event['attempts'] !== 0 || $event['dead_lettered_at'] !== null
        || (int) $db->fetchOne("SELECT count(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.email = :email AND n.event_type = 'user.registered'", ['email' => 'worker-command@baander.app']) !== 1
        || (int) $db->fetchOne("SELECT count(*) FROM domain_event_outbox_receipt WHERE outbox_id = :id AND consumer = 'notifications.v1'", ['id' => $event['id']]) !== 1
        || (int) $db->fetchOne('SELECT count(*) FROM domain_event_outbox_delivery') !== 2
        || (int) $db->fetchOne("SELECT count(*) FROM domain_event_outbox_delivery d JOIN notifications n ON n.public_id = d.notification_id JOIN users u ON u.id = n.user_id WHERE u.email = :email AND d.channel IN ('push', 'webhook') AND d.relayed_at IS NOT NULL AND d.attempts = 0 AND d.dead_lettered_at IS NULL", ['email' => 'worker-command@baander.app']) !== 2) {
        throw new RuntimeException('Real command has not committed the expected projection, receipt and two durable Redis handoffs.');
    }
    $redis = new Redis();
    $redis->connect('redis', 6379, 1.0);
    $redis->auth(['default', 'test-only']);
    $pending = $redis->xPending('messages', 'baander');
    if (!is_array($pending) || ($pending[0] ?? null) !== 0 || $redis->xLen('messages') !== 0
        || $redis->xLen('failed_messages') !== 0 || $redis->zCard('messages__queue') !== 0
        || $redis->zCard('failed_messages__queue') !== 0) {
        throw new RuntimeException('Confirmed handoffs have not both been consumed and acknowledged without retries/dead letters.');
    }
    if ($mode === 'replay-outbox') {
        $db->executeStatement('UPDATE domain_event_outbox SET relayed_at = NULL WHERE id = :id', ['id' => $event['id']]);
        echo "Simulated lost event acknowledgment after committed effects.\n";
    } else {
        echo "Independent observer verified one projection/receipt, two handoffs and clean async acknowledgments.\n";
    }
    exit(0);
}
$row = $db->fetchAssociative('SELECT owner_boot_id, state, epoch FROM worker_deployment_leases WHERE namespace = :namespace', ['namespace' => $namespace]);
if ($row === false || $row['owner_boot_id'] !== $bootId || $row['state'] !== 'active' || (int) $row['epoch'] !== 1) {
    throw new RuntimeException('Expected the original active, epoch-one deployment reservation.');
}
if ($mode === 'expire') {
    $changed = $db->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - INTERVAL '1 second' WHERE namespace = :namespace AND owner_boot_id = :boot AND state = 'active' AND epoch = 1", ['namespace' => $namespace, 'boot' => $bootId]);
    if ($changed !== 1) {
        throw new RuntimeException('Could not expire the exact disposable deployment reservation.');
    }
    echo "Expired the exact original reservation; ownership remains active.\n";
    exit(0);
}
if ($mode === 'reserved') {
    echo "Original deployment lease remains reserved.\n";
    exit(0);
}

$roles = [];
foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
    $pid = (int) basename(dirname($path));
    $command = @file_get_contents($path);
    if ($pid === 1 || $pid === getmypid() || $command === false) {
        continue;
    }
    $arguments = explode("\0", rtrim($command, "\0"));
    $role = in_array('messenger:consume', $arguments, true) ? 'consumer'
        : (in_array('app:outbox:consume', $arguments, true) ? 'relay' : null);
    if ($role === null) {
        continue;
    }
    $status = @file_get_contents(dirname($path) . '/status');
    if ($status === false || preg_match('/^PPid:\s+(\d+)$/m', $status, $matches) !== 1 || (int) $matches[1] !== 1) {
        throw new RuntimeException('Production role is not a direct child of PID 1.');
    }
    if (isset($roles[$role])) {
        throw new RuntimeException('Duplicate production worker role.');
    }
    $roles[$role] = $pid;
}
if (count($roles) !== 2 || !isset($roles['consumer'], $roles['relay'])) {
    throw new RuntimeException('Both production roles have not started.');
}
$redis = new Redis();
$redis->connect('redis', 6379, 1.0);
$redis->auth(['default', 'test-only']);
$consumers = $redis->xInfo('CONSUMERS', 'messages', 'baander');
$expected = 'worker-' . substr(hash('sha256', $namespace), 0, 16) . '-' . $bootId . '-consumer-1';
if (!is_array($consumers) || !in_array($expected, array_column($consumers, 'name'), true)) {
    throw new RuntimeException('Expected Redis consumer ' . $expected . '; observed ' . json_encode($consumers, JSON_THROW_ON_ERROR));
}
if ($mode === 'kill-consumer' && !posix_kill($roles['consumer'], SIGKILL)) {
    throw new RuntimeException('Could not kill the disposable consumer fixture.');
}
echo json_encode(['namespace' => $namespace, 'bootId' => $bootId, 'consumer' => $roles['consumer'], 'relay' => $roles['relay']], JSON_THROW_ON_ERROR) . "\n";
