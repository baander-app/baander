#!/usr/bin/env php
<?php

declare(strict_types=1);

$arguments = array_slice($_SERVER['argv'], 1);
$root = getenv('WORKER_SAMPLER_FAKE_ROOT');
file_put_contents(
    $root . '/commands.jsonl',
    json_encode($arguments, JSON_THROW_ON_ERROR) . "\n",
    FILE_APPEND | LOCK_EX,
);
if (in_array($arguments[0] ?? '', ['--host', '--context'], true)) {
    $arguments = array_slice($arguments, 2);
}
if (($arguments[0] ?? '') === 'context') {
    echo ($arguments[1] ?? '') === 'inspect' ? json_encode([['Endpoints' => ['docker' => ['Host' => 'unix:///fixture.sock']]]], JSON_THROW_ON_ERROR) : 'fixture';
    exit(0);
}
if (($arguments[0] ?? '') === 'info') {
    echo json_encode(['ID' => 'fixture-daemon']);
    exit(0);
}
if (($arguments[0] ?? '') === 'inspect') {
    $web = $arguments[1] === 'clock-web';
    echo json_encode(
        [[
            'Id' => str_repeat($web ? 'b' : 'a', 64),
            'Name' => $web ? '/baander-sampler-web' : '/baander-sampler-postgres',
            'State' => ['Running' => true],
        ]],
        JSON_THROW_ON_ERROR,
    );
    exit(0);
}
if (in_array('php', $arguments, true)) {
    echo '9876.5';
    exit(0);
}
if (getenv('WORKER_SAMPLER_EMPTY') === '1') {
    exit(0);
}
$records = [
    '/sys/fs/cgroup/memory.current' => "100\n",
    '/sys/fs/cgroup/memory.events' => "oom_kill 0\n",
    '/sys/fs/cgroup/memory.stat' => "anon 50\n",
    '/sys/fs/cgroup/memory.max' => "2147483648\n",
    '/sys/fs/cgroup/pids.current' => "3\n",
    '/proc/42/status' => "Name: postgres\nState: S (sleeping)\nPPid: 1\n",
    '/proc/42/stat' => '42 (postgres) S ' . implode(' ', array_fill(0, 18, '0')) . ' 1000 0',
    '/proc/42/cmdline' => "postgres\0-D\0/db\0",
    '/proc/42/smaps_rollup' => "Pss: 10 kB\n",
];
foreach ($records as $path => $value) {
    echo $path, "\n", base64_encode($value), "\n.\n";
}
