<?php

declare(strict_types=1);

// Runs only through docker exec in the disposable execution cgroup.
$mode = $argv[1] ?? '';
$seconds = filter_var($argv[2] ?? '75', FILTER_VALIDATE_INT);
if ($seconds === false || $seconds < 1 || $seconds > 300) {
    throw new InvalidArgumentException('Pressure duration must be between one and 300 seconds.');
}
$deadline = hrtime(true) + $seconds * 1_000_000_000;
$startTicks = static function (int $pid): ?string {
    $stat = @file_get_contents('/proc/' . $pid . '/stat');
    if (!is_string($stat) || ($end = strrpos($stat, ') ')) === false) { return null; }
    return explode(' ', substr($stat, $end + 2))[19] ?? null;
};

if ($mode === 'cpu') {
    $audio = [];
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
        $arguments = @file_get_contents($path);
        if (is_string($arguments) && str_contains($arguments, '/media/source/track.flac') && str_contains($arguments, 'ffmpeg')) {
            $pid = (int) basename(dirname($path));
            $ticks = $startTicks($pid);
            if ($ticks !== null && $startTicks($pid) === $ticks && posix_kill($pid, SIGSTOP)) { $audio[$pid] = $ticks; }
        }
    }
    $resumeAudio = static function () use ($audio, $startTicks): void {
        foreach ($audio as $pid => $ticks) {
            if ($startTicks($pid) === $ticks) { posix_kill($pid, SIGCONT); }
        }
    };
    register_shutdown_function($resumeAudio);
    if ($audio === []) { throw new RuntimeException('No admitted audio process to hold.'); }
    echo json_encode(['heldAudioIdentities' => $audio], JSON_THROW_ON_ERROR) . "\n";
    flush();
    $children = [];
    foreach (range(1, 4) as $_) {
        $pid = pcntl_fork();
        if ($pid === -1) { throw new RuntimeException('Cannot create CPU pressure process.'); }
        if ($pid === 0) {
            // Each burner stands in for an encode: the native daemon starts encodes at nice 10
            // with the media work out-of-memory score, so the burners run the same way.
            if (!pcntl_setpriority(10) || file_put_contents('/proc/self/oom_score_adj', '600') === false) {
                throw new RuntimeException('Cannot lower the CPU pressure priority.');
            }
            echo json_encode(['burner' => getmypid(), 'nice' => pcntl_getpriority(),
                'oomScoreAdj' => (int) trim((string) file_get_contents('/proc/self/oom_score_adj'))], JSON_THROW_ON_ERROR) . "\n";
            flush();
            $digest = str_repeat('capacity', 4096);
            while (hrtime(true) < $deadline) { $digest = hash('sha256', $digest . str_repeat('capacity', 4096)); }
            echo json_encode(['cpuDigest' => $digest], JSON_THROW_ON_ERROR) . "\n";
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) { pcntl_waitpid($pid, $status); }
    $resumeAudio();
} elseif ($mode === 'native-loss') {
    // Ends the exact native role with SIGKILL, as the kernel's out-of-memory killer would end a
    // victim, without exhausting memory or PIDs on a host whose worker units carry no limits.
    $target = null;
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
        $raw = @file_get_contents($path);
        $arguments = is_string($raw) ? explode("\0", rtrim($raw, "\0")) : [];
        if (in_array(dirname(__DIR__, 3) . '/bin/worker-native.php', $arguments, true)) {
            if ($target !== null) { throw new RuntimeException('Native supervisor role is ambiguous.'); }
            $pid = (int) basename(dirname($path));
            $ticks = $startTicks($pid);
            if ($ticks === null) { throw new RuntimeException('Cannot identify native supervisor role.'); }
            $target = ['pid' => $pid, 'startTicks' => $ticks, 'argv' => $arguments];
        }
    }
    if ($target === null) { throw new RuntimeException('Native supervisor role is missing.'); }
    $priority = static function (int $pid): array {
        $stat = (string) @file_get_contents('/proc/' . $pid . '/stat');
        $end = strrpos($stat, ') ');
        return ['nice' => $end === false ? null : (int) (explode(' ', substr($stat, $end + 2))[16] ?? 0),
            'oomScoreAdj' => (int) trim((string) @file_get_contents('/proc/' . $pid . '/oom_score_adj'))];
    };
    $killOrder = ['supervisor' => ['pid' => 1, ...$priority(1)], 'native' => ['pid' => $target['pid'], ...$priority($target['pid'])]];
    // The target is recorded first: the pair drains, and may end this process, once the role dies.
    echo json_encode(['nativeTarget' => $target, 'killOrder' => $killOrder], JSON_THROW_ON_ERROR) . "\n";
    flush();
    if ($startTicks($target['pid']) !== $target['startTicks'] || !posix_kill($target['pid'], SIGKILL)) {
        throw new RuntimeException('Cannot end the exact native role.');
    }
    while (hrtime(true) < $deadline) {
        if ($startTicks($target['pid']) !== $target['startTicks']) {
            echo json_encode(['nativeDied' => $target, 'monotonic' => hrtime(true) / 1e9], JSON_THROW_ON_ERROR) . "\n";
            flush();
            exit(0);
        }
        usleep(10000);
    }
    throw new RuntimeException('Selected native role survived SIGKILL.');
} else {
    throw new InvalidArgumentException('Expected cpu or native-loss pressure.');
}
