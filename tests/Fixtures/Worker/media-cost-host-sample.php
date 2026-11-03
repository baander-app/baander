<?php

declare(strict_types=1);

// Samples the cgroups of named disposable containers from the Docker host.
// Arguments: seconds, then role=containerId pairs. Each line is one sample.

$arguments = $_SERVER['argv'] ?? [];
$seconds = (float) ($arguments[1] ?? 60);
$paths = [];
foreach (array_slice($arguments, 2) as $pair) {
    [$role, $id] = explode('=', $pair, 2);
    if (preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1) {
        throw new InvalidArgumentException('Host cost sampling requires full container identifiers.');
    }
    // systemd and cgroupfs drivers place container cgroups differently.
    $paths[$role] = null;
    foreach (['/sys/fs/cgroup/system.slice/docker-' . $id . '.scope', '/sys/fs/cgroup/docker/' . $id] as $candidate) {
        if (is_file($candidate . '/cpu.stat')) {
            $paths[$role] = $candidate;
            break;
        }
    }
}
echo json_encode(['header' => ['unit' => 'host', 'cgroups' => $paths]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
$deadline = hrtime(true) + (int) ($seconds * 1e9);
while (hrtime(true) < $deadline) {
    $sample = ['t' => hrtime(true) / 1e9, 'loadavg' => trim((string) @file_get_contents('/proc/loadavg')), 'containers' => []];
    foreach ($paths as $role => $path) {
        if ($path === null) {
            continue;
        }
        $cpu = (string) @file_get_contents($path . '/cpu.stat');
        $memory = (string) @file_get_contents($path . '/memory.stat');
        $sample['containers'][$role] = [
            'usageUsec' => preg_match('/^usage_usec (\d+)/m', $cpu, $usage) === 1 ? (int) $usage[1] : null,
            'anonBytes' => preg_match('/^anon (\d+)/m', $memory, $anon) === 1 ? (int) $anon[1] : null,
            'fileBytes' => preg_match('/^file (\d+)/m', $memory, $file) === 1 ? (int) $file[1] : null,
            'memoryCurrent' => is_string($current = @file_get_contents($path . '/memory.current')) ? (int) $current : null,
        ];
    }
    echo json_encode($sample, JSON_THROW_ON_ERROR) . "\n";
    flush();
    if (($stopFile = getenv('BAANDER_TEST_SAMPLER_STOP_FILE')) !== false && is_file($stopFile)) {
        break;
    }
    usleep(500_000);
}
