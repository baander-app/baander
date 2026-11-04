<?php

declare(strict_types=1);

// Samples one disposable worker unit for the load proof and writes one JSON line per record.
// Every ten milliseconds it looks for lease helper processes; each helper's record gives its
// lifetime from start until its supervisor reaped it, which is the interval the supervisor's
// lease deadline bounds. Every half second it records the unit's cgroup counters, the kill
// order of its processes and both units' heartbeats.
// Arguments: seconds to run, unit (control or execution).

require_once __DIR__ . '/KillOrderSample.php';

[$seconds, $unit] = [(float) ($argv[1] ?? 600), $argv[2] ?? 'control'];
$heartbeats = '/run/baander-worker/' . getenv('BAANDER_WORKER_BOOT_ID');
$deadline = hrtime(true) + (int) ($seconds * 1e9);
$emit = static function (array $record): void {
    echo json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
    flush();
};
$read = static fn (string $path, int $length = 65_536): ?string => is_string($value = @file_get_contents($path, false, null, 0, $length)) ? $value : null;
$uptime = static fn (): float => (float) explode(' ', (string) $read('/proc/uptime'))[0];
/** @var array<int,array{startTicks:int,action:?string,sequence:?int,firstSeen:float}> $agents */
$agents = [];
/** @var array<int,int> $settled PIDs whose arguments were read once they were old enough to have executed. */
$settled = [];
$nextSample = 0.0;
while (hrtime(true) < $deadline) {
    $now = $uptime();
    $present = [];
    foreach (scandir('/proc') ?: [] as $entry) {
        if (!ctype_digit($entry)) {
            continue;
        }
        $pid = (int) $entry;
        $present[$pid] = true;
        if (isset($agents[$pid]) || isset($settled[$pid])) {
            continue;
        }
        $stat = $read('/proc/' . $pid . '/stat', 4096);
        if ($stat === null || ($close = strrpos($stat, ')')) === false) {
            continue;
        }
        $startTicks = (int) explode(' ', trim(substr($stat, $close + 2)))[19];
        $arguments = explode("\0", rtrim((string) $read('/proc/' . $pid . '/cmdline'), "\0"));
        if (array_any($arguments, static fn (string $argument): bool => str_ends_with($argument, '/bin/worker-lease-agent.php'))) {
            $request = json_decode((string) base64_decode((string) end($arguments), true), true);
            $agents[$pid] = ['startTicks' => $startTicks, 'action' => is_array($request) ? ($request['action'] ?? null) : null,
                'sequence' => is_array($request) ? ($request['sequence'] ?? null) : null, 'firstSeen' => $now];
        } elseif ($now - $startTicks / 100 > 1.0) {
            // A fresh fork shows its parent's arguments until it executes; only an older process is settled.
            $settled[$pid] = $startTicks;
        }
    }
    foreach ($agents as $pid => $agent) {
        if (!isset($present[$pid])) {
            $emit(['type' => 'leaseAgent', 'unit' => $unit, 'pid' => $pid, 'action' => $agent['action'], 'sequence' => $agent['sequence'],
                'startedAt' => $agent['startTicks'] / 100, 'reapedBy' => $now, 'seconds' => round($now - $agent['startTicks'] / 100, 3)]);
            unset($agents[$pid]);
        }
    }
    $settled = array_intersect_key($settled, $present);
    if ($now >= $nextSample) {
        $nextSample = $now + .5;
        $sample = ['type' => 'sample', 'unit' => $unit, 'monotonic' => hrtime(true) / 1e9, 'uptime' => $now];
        foreach (['memory.events', 'memory.current', 'memory.peak', 'cpu.stat'] as $file) {
            $sample[$file] = $read('/sys/fs/cgroup/' . $file);
        }
        $sample['killOrder'] = KillOrderSample::processes();
        foreach (['control', 'execution'] as $peer) {
            $heartbeat = $read($heartbeats . '/' . $peer . '.json');
            $sample['heartbeats'][$peer] = $heartbeat === null ? null : json_decode($heartbeat, true);
        }
        $emit($sample);
    }
    if (($stopFile = getenv('BAANDER_TEST_SAMPLER_STOP_FILE')) !== false && is_file($stopFile)) {
        break;
    }
    usleep(10_000);
}
