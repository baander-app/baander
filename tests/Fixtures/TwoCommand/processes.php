<?php

declare(strict_types=1);

$procRoot = $argv[2] ?? '/proc';
$processes = [];
foreach (glob($procRoot . '/[0-9]*/stat') ?: [] as $file) {
    $stat = @file_get_contents($file);
    if ($stat === false || preg_match('/^(\d+) \((.*)\) (.*)$/s', $stat, $match) !== 1) {
        continue;
    }
    $fields = explode(' ', $match[3]);
    $pid = (int) $match[1];
    $arguments = array_values(array_filter(explode("\0", (string) @file_get_contents($procRoot . '/' . $pid . '/cmdline')), static fn (string $value): bool => $value !== ''));
    $processes[$pid] = ['pid' => $pid, 'ppid' => (int) $fields[1], 'state' => $fields[0], 'startTicks' => (int) $fields[19], 'rssPages' => (int) $fields[21], 'arguments' => $arguments];
}
$encoders = [];
foreach ($processes as $process) {
    $binary = basename($process['arguments'][0] ?? '');
    if (!in_array($binary, ['ffmpeg', 'ffprobe'], true)) {
        continue;
    }
    $ancestry = [];
    $parent = $process['ppid'];
    while ($parent > 0 && isset($processes[$parent]) && !isset($ancestry[$parent])) {
        $ancestry[$parent] = $processes[$parent]['arguments'];
        $parent = $processes[$parent]['ppid'];
    }
    if (($argv[1] ?? '') === 'web') {
        throw new RuntimeException('An encoder or probe exists in the web container.');
    }
    if (!str_contains(json_encode($ancestry, JSON_THROW_ON_ERROR), 'worker-native.php')) {
        throw new RuntimeException('Encoder is outside the native execution manager.');
    }
    $encoders[] = $process + ['ancestry' => $ancestry];
}
/** @param list<string> $arguments */
$isBackgroundExecutor = static function (array $arguments): bool {
    $binary = basename(array_shift($arguments) ?? '');
    if (preg_match('/\Acpu-pool-worker-[0-9]+\z/D', $binary) === 1) {
        return true;
    }
    $scripts = ['worker-media.php', 'worker-media-job.php', 'worker-scheduler.php', 'worker-deployment.php', 'worker-lease-agent.php', 'worker-native.php'];
    if (in_array($binary, $scripts, true)) {
        return true;
    }
    if (preg_match('/\Aphp(?:[0-9]+(?:\.[0-9]+)*)?\z/D', $binary) !== 1) {
        return false;
    }
    $script = '';
    while ($arguments !== []) {
        $argument = array_shift($arguments);
        if (in_array($argument, ['-d', '--define', '-c', '--php-ini', '-z', '--zend-extension'], true)) {
            array_shift($arguments);
        } elseif (in_array($argument, ['--', '-f', '--file'], true)) {
            $script = array_shift($arguments) ?? '';
            break;
        } elseif (!str_starts_with($argument, '-')) {
            $script = $argument;
            break;
        }
    }
    if (in_array(basename($script), $scripts, true)) {
        return true;
    }
    if (basename($script) !== 'console') {
        return false;
    }
    while ($arguments !== []) {
        $argument = array_shift($arguments);
        if (in_array($argument, ['--env', '-e'], true)) {
            array_shift($arguments);
        } elseif (!str_starts_with($argument, '-')) {
            return in_array($argument, ['messenger:consume', 'app:outbox:consume', 'app:worker'], true);
        }
    }

    return false;
};
if (($argv[1] ?? '') === 'web') {
    foreach ($processes as $process) {
        if ($isBackgroundExecutor($process['arguments'])) {
            throw new RuntimeException('A background executor exists in the web container.');
        }
    }
}
$resources = [];
foreach (['memory.current', 'memory.peak', 'memory.max', 'pids.current', 'pids.max', 'cpu.stat', 'cpu.max'] as $name) {
    $value = @file_get_contents('/sys/fs/cgroup/' . $name);
    $resources[$name] = $value === false ? null : trim($value);
}
echo json_encode(['at' => microtime(true), 'processes' => array_values($processes), 'encoders' => $encoders, 'resources' => $resources], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
