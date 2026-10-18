<?php

declare(strict_types=1);

// Runs only through docker exec in the disposable execution cgroup.
$mode = $argv[1] ?? '';
$deadline = hrtime(true) + 75_000_000_000;
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
            $digest = str_repeat('capacity', 4096);
            while (hrtime(true) < $deadline) { $digest = hash('sha256', $digest . str_repeat('capacity', 4096)); }
            echo json_encode(['cpuDigest' => $digest], JSON_THROW_ON_ERROR) . "\n";
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) { pcntl_waitpid($pid, $status); }
    $resumeAudio();
} elseif ($mode === 'memory') {
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
    $scorePath = '/proc/' . $target['pid'] . '/oom_score_adj';
    if ($startTicks($target['pid']) !== $target['startTicks'] || file_put_contents($scorePath, '1000') !== 4
        || trim((string) file_get_contents($scorePath)) !== '1000') {
        throw new RuntimeException('Cannot select the exact native role as the execution OOM victim.');
    }
    echo json_encode(['oomTarget' => $target, 'oomScoreAdj' => 1000], JSON_THROW_ON_ERROR) . "\n";
    flush();
    $blocks = [];
    while (hrtime(true) < $deadline) {
        if ($startTicks($target['pid']) !== $target['startTicks']) {
            echo json_encode(['oomTargetDied' => $target, 'monotonic' => hrtime(true) / 1e9], JSON_THROW_ON_ERROR) . "\n";
            flush();
            $blocks = [];
            exit(0);
        }
        $blocks[] = str_repeat('m', 16 * 1024 * 1024);
        usleep(10000);
    }
    throw new RuntimeException('Selected native role survived memory pressure.');
} elseif ($mode === 'pid') {
    $children = [];
    $holdUntil = null;
    $refusals = 0;
    while (hrtime(true) < $deadline && ($holdUntil === null || hrtime(true) < $holdUntil)) {
        $pid = @pcntl_fork();
        if ($pid === -1) {
            ++$refusals;
            if ($holdUntil === null) {
                $holdUntil = hrtime(true) + 5_000_000_000;
                $kernel = ['monotonic' => hrtime(true) / 1e9];
                foreach (['pids.events', 'pids.current', 'pids.max'] as $file) {
                    $kernel[$file] = file_get_contents('/sys/fs/cgroup/' . $file);
                }
                $kernel['cgroup'] = file_get_contents('/proc/self/cgroup');
                echo json_encode(['forkRefused' => true, 'children' => count($children), 'monotonic' => hrtime(true) / 1e9,
                    'executionCgroup' => $kernel], JSON_THROW_ON_ERROR) . "\n";
                flush();
            }
            // Refill slots released by short-lived probes and lease observers.
            // Sleeping at the first refusal would let them reuse that free slot.
            usleep(1000);
            continue;
        }
        if ($pid === 0) { sleep(15); exit(0); }
        $children[] = $pid;
        if ($holdUntil !== null) {
            echo json_encode(['refilledPid' => $pid, 'startTicks' => $startTicks($pid), 'monotonic' => hrtime(true) / 1e9], JSON_THROW_ON_ERROR) . "\n";
            flush();
        }
    }
    echo json_encode(['holdFinished' => true, 'forkRefusals' => $refusals, 'children' => count($children), 'monotonic' => hrtime(true) / 1e9], JSON_THROW_ON_ERROR) . "\n";
    flush();
    foreach ($children as $pid) { pcntl_waitpid($pid, $status); }
} else {
    throw new InvalidArgumentException('Expected cpu, memory or pid pressure.');
}
