<?php

declare(strict_types=1);

// Replays the application's recorded 1080p encoder command several times at once
// inside the execution unit, beyond the slot count the media role admits, so the
// per-encode cost at a larger slot count can be measured on the same CPUs.
// Arguments: requested encoders, seconds to run, encoder name, MiB per encoder.

require __DIR__ . '/MediaControlObservation.php';

[$requested, $seconds, $encoder, $perEncoderMiB] = [(int) ($argv[1] ?? 8), (float) ($argv[2] ?? 45), $argv[3] ?? 'libx264', (int) ($argv[4] ?? 600)];
$source = null;
foreach (MediaControlObservation::records() as $record) {
    $arguments = $record['argv'];
    $codec = array_search('-c:v', $arguments, true);
    if ($codec !== false && ($arguments[$codec + 1] ?? null) === $encoder && in_array('/media/source/cost-1080p.mkv', $arguments, true) && !in_array('-ss', $arguments, true)) {
        $source = $arguments;
        break;
    }
}
if ($source === null) {
    throw new RuntimeException('No recorded 1080p ' . $encoder . ' encode to replay.');
}
// Stay inside the unit's memory limit so the replay cannot contain the deployment.
$limit = (int) file_get_contents('/sys/fs/cgroup/memory.max');
$current = (int) file_get_contents('/sys/fs/cgroup/memory.current');
$count = max(1, min($requested, intdiv($limit - $current - 512 * 1048576, $perEncoderMiB * 1048576)));
$root = dirname(__DIR__, 3) . '/storage/two-command/cost-replay';
$processes = [];
$startedAt = hrtime(true) / 1e9;
foreach (range(1, $count) as $index) {
    $directory = $root . '/' . $index;
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException('Cannot create replay output.');
    }
    $arguments = $source;
    $arguments[array_key_last($arguments)] = $directory . '/manifest.mpd';
    // The observation wrapper adds progress reporting and executes the native binary.
    $process = proc_open(['/usr/bin/ffmpeg', ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Replay encoder did not start.');
    }
    $processes[] = $process;
}
usleep((int) ($seconds * 1e6));
$endedAt = hrtime(true) / 1e9;
foreach ($processes as $process) {
    proc_terminate($process, SIGTERM);
}
$deadline = hrtime(true) / 1e9 + 10;
$running = [];
foreach ($processes as $process) {
    while (proc_get_status($process)['running'] && hrtime(true) / 1e9 < $deadline) {
        usleep(50_000);
    }
    $running[] = proc_get_status($process)['running'];
    if (end($running)) {
        proc_terminate($process, SIGKILL);
    }
    proc_close($process);
}
foreach (glob($root . '/*/*') ?: [] as $file) {
    unlink($file);
}
echo json_encode([
    'name' => 'replay-' . $count . '-' . $encoder,
    'startedAt' => $startedAt,
    'endedAt' => $endedAt,
    'requested' => $requested,
    'encoders' => $count,
    'memoryLimitMiB' => intdiv($limit, 1048576),
    'perEncoderMiB' => $perEncoderMiB,
    'killedAfterGrace' => count(array_filter($running)),
    'arguments' => $source,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
