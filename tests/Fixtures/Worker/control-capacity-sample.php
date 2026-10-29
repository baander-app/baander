<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$seconds = (float) ($argv[1] ?? 65);
$mode = $argv[2] ?? 'control';
$db = null;
if ($mode === 'control') {
    $db = Doctrine\DBAL\DriverManager::getConnection((new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse((string) getenv('DATABASE_URL')));
}
$redis = null;
if ($db !== null) {
    $redis = new Redis(); $redis->connect('redis', 6379, 1); $redis->auth(['default', 'test-only']);
}
$deadline = hrtime(true) + (int) ($seconds * 1e9);
while (hrtime(true) < $deadline) {
    $sample = ['monotonic' => hrtime(true) / 1e9];
    foreach (['cpu.stat', 'memory.current', 'memory.events', 'memory.peak', 'pids.current', 'pids.events', 'cpuset.cpus.effective', 'cpu.max', 'memory.max', 'pids.max'] as $file) {
        $sample[$file] = @file_get_contents('/sys/fs/cgroup/' . $file);
    }
    $sample['cgroup'] = @file_get_contents('/proc/self/cgroup');
    if ($db === null) {
        $sample['nativeProcesses'] = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && str_contains($raw, 'ffmpeg')) {
                $sample['nativeProcesses'][] = ['pid' => (int) basename(dirname($path)), 'argv' => explode("\0", rtrim($raw, "\0")), 'stat' => @file_get_contents(dirname($path) . '/stat')];
            }
        }
    }
    if ($db !== null) {
        $sample['ordinaryReceiverDepth'] = $redis->xLen('messages');
        $path = '/run/baander-worker/' . getenv('BAANDER_WORKER_BOOT_ID') . '/control.json';
        $sample['heartbeat'] = json_decode((string) @file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $sample['lease'] = $db->fetchAssociative('SELECT owner_boot_id, epoch, state, expires_at > clock_timestamp() AS unexpired FROM worker_deployment_leases WHERE namespace = ?', [getenv('BAANDER_WORKER_NAMESPACE')]);
    }
    echo json_encode($sample, JSON_THROW_ON_ERROR) . "\n";
    flush();
    if (($stopFile = getenv('BAANDER_TEST_SAMPLER_STOP_FILE')) !== false && is_file($stopFile)) { break; }
    usleep(50000);
}
