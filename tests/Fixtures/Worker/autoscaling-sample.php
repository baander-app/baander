<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$unit = $argv[1] ?? '';
$seconds = (int) ($argv[2] ?? 480);
if (!in_array($unit, ['control', 'execution'], true) || $seconds < 1 || $seconds > 500) {
    throw new InvalidArgumentException('Invalid bounded sampler.');
}
$db = $unit === 'control' ? Doctrine\DBAL\DriverManager::getConnection((new Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse((string) getenv('DATABASE_URL'))) : null;
$directory = (getenv('BAANDER_WORKER_IPC_DIRECTORY') ?: '/run/baander-worker') . '/' . getenv('BAANDER_WORKER_BOOT_ID');
$deadline = hrtime(true) / 1e9 + $seconds;
while (hrtime(true) / 1e9 < $deadline) {
    $sample = ['monotonic' => hrtime(true) / 1e9, 'wallTime' => microtime(true), 'unit' => $unit];
    foreach (['cpu.stat', 'memory.current', 'memory.events', 'memory.peak', 'pids.current', 'pids.events', 'cpuset.cpus.effective', 'cpu.max', 'memory.max', 'pids.max'] as $name) {
        $sample[$name] = @file_get_contents('/sys/fs/cgroup/' . $name);
    }
    foreach (['admission', 'resource', 'control', 'execution'] as $name) {
        $raw = @file_get_contents($directory . '/' . $name . '.json', false, null, 0, 32769);
        $sample[$name] = is_string($raw) && strlen($raw) <= 32768 ? json_decode($raw, true) : null;
    }
    if (is_array($sample['admission'])) {
        $state = $sample['admission'];
        $profileFields = json_decode((string) file_get_contents(__DIR__ . '/autoscaling-profile.json'), true, flags: JSON_THROW_ON_ERROR);
        $profileFields['maximumConcurrency'] = count($state['slots']);
        $profile = App\Shared\Application\DTO\WorkerAutoscalingProfile::fromJson(json_encode($profileFields, JSON_THROW_ON_ERROR));
        $lease = new App\Shared\Infrastructure\Worker\DeploymentLease($state['namespace'], $state['bootId'], $state['epoch']);
        $sample['status'] = (new App\Shared\Infrastructure\Worker\Autoscaling\AutoscalingStatus(dirname($directory), $lease, $profile))->snapshot(hrtime(true) / 1e9);
    }
    $sample['processes'] = [];
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || ($end = strrpos($raw, ') ')) === false) { continue; }
        $fields = explode(' ', substr($raw, $end + 2));
        $arguments = @file_get_contents(dirname($path) . '/cmdline');
        $status = @file_get_contents(dirname($path) . '/status');
        $again = @file_get_contents($path);
        if (!is_string($again) || ($againEnd = strrpos($again, ') ')) === false
            || (explode(' ', substr($again, $againEnd + 2))[19] ?? null) !== $fields[19]) { continue; }
        $rssKiB = is_string($status) && preg_match('/^VmRSS:\s+([0-9]+) kB$/m', $status, $match) === 1 ? (int) $match[1] : null;
        $sample['processes'][] = ['pid' => (int) basename(dirname($path)), 'userTicks' => $fields[11], 'systemTicks' => $fields[12],
            'startTicks' => $fields[19], 'rssPages' => $fields[21], 'rssBytes' => $rssKiB === null ? null : $rssKiB * 1024, 'cmdline' => $arguments];
    }
    if ($db !== null) {
        $sample['lease'] = $db->fetchAssociative('SELECT owner_boot_id, epoch, state, expires_at > clock_timestamp() AS unexpired FROM worker_deployment_leases WHERE namespace = ?', [getenv('BAANDER_WORKER_NAMESPACE')]);
        $sample['connections'] = (int) $db->fetchOne('SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()');
        $sample['completions'] = @file_get_contents($directory . '/autoscaling-completions.jsonl', false, null, 0, 65537) ?: '';
    }
    $sample['capturedAt'] = hrtime(true) / 1e9;
    echo json_encode($sample, JSON_THROW_ON_ERROR) . "\n";
    flush();
    usleep(250000);
}
