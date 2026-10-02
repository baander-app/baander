<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$script, $mode, $namespace, $bootId] = $argv + [null, null, null, null];
if (!in_array($mode, ['ready', 'kill-consumer', 'reserved', 'expire'], true)
    || !is_string($namespace) || !is_string($bootId)) {
    throw new RuntimeException('Invalid isolated worker check request.');
}
App\Shared\Infrastructure\Worker\DeploymentLease::validateIdentity($namespace, $bootId);
$url = getenv('DATABASE_URL');
if (!$url) {
    throw new RuntimeException('Disposable DATABASE_URL is required.');
}
$db = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
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
