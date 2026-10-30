#!/usr/bin/env php
<?php

declare(strict_types=1);

$arguments = array_slice($_SERVER['argv'], 1);
if (in_array($arguments[0] ?? '', ['--context', '--host'], true)) {
    $arguments = array_slice($arguments, 2);
}
file_put_contents(
    getenv('WORKER_LEGACY_COMMANDS'),
    json_encode($arguments, JSON_THROW_ON_ERROR) . "\n",
    FILE_APPEND | LOCK_EX,
);
require dirname(__DIR__, 4) . '/vendor/autoload.php';
\Baander\Tooling\Tests\Fixtures\WorkerResourceState::handle($arguments);
if (in_array('redis-cli', $arguments, true)) {
    echo 'PONG';
}
if (($arguments[0] ?? '') === 'ps') {
    $filter = $arguments[array_search('--filter', $arguments, true) + 1];
    if (str_starts_with($filter, 'name=^/')) {
        echo 'fixture-container';
    }
}
if (in_array('tests/Fixtures/Worker/legacy-runtime.php', $arguments, true)) {
    $stat = file_get_contents('/proc/self/stat');
    $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
    Swoole\Process::kill((int) $fields[1], 15);
    sleep(3);
}
if (($arguments[0] ?? '') === 'cp') {
    $destination = $arguments[2];
    mkdir($destination, 0700, true);
    file_put_contents($destination . '/supervisord.log', "interrupted supervisor evidence\n");
}
