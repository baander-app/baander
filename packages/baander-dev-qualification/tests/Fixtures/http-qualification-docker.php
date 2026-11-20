#!/usr/bin/env php
<?php

declare(strict_types=1);

$arguments = array_slice($_SERVER['argv'], 1);
file_put_contents(getenv('HTTP_FIXTURE_COMMANDS'), json_encode($arguments) . "\n", FILE_APPEND);
if (array_splice($arguments, 0, 2) !== ['--host', getenv('HTTP_FIXTURE_ENDPOINT') ?: 'unix:///http-fixture.sock']) {
    exit(98);
}
$marker = getenv('HTTP_FIXTURE_MARKER');
$mode = getenv('HTTP_FIXTURE_MODE');
$command = $arguments[0] ?? '';
if ($command === 'info') {
    echo '{"ID":"fixture-daemon"}';
} elseif ($command === 'compose') {
    fwrite(STDERR, "Compose is deliberately unavailable.\n");
    exit(88);
} elseif ($command === 'create') {
    $name = $arguments[array_search('--name', $arguments, true) + 1];
    $label = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
    file_put_contents($marker, json_encode(['Id' => str_repeat('a', 64), 'Name' => '/' . $name, 'Config' => ['Labels' => [$label[0] => $label[1]]]]));
    echo str_repeat('a', 64);
} elseif ($command === 'start' && $mode === 'cancellation') {
    preg_match('/^PPid:\s+(\d+)$/m', file_get_contents('/proc/self/status'), $match);
    Swoole\Process::kill((int) $match[1], 15);
    sleep(2);
} elseif (array_slice($arguments, 0, 2) === ['container', 'inspect']) {
    if (is_file($marker)) {
        echo '[' . file_get_contents($marker) . ']';
    } else {
        fwrite(STDERR, 'Error: No such container: ' . end($arguments));
        exit(1);
    }
} elseif (array_slice($arguments, 0, 2) === ['container', 'ls']) {
    if (is_file($marker)) {
        echo json_encode(['Names' => ltrim(json_decode(file_get_contents($marker), true)['Name'], '/')]);
    }
} elseif ($command === 'rm') {
    if (in_array($mode, ['cleanup-failure', 'primary-cleanup-failure'], true)) {
        fwrite(STDERR, "fixture removal failed\n");
        exit(9);
    }
    if ($mode === 'cancellation') {
        preg_match('/^PPid:\s+(\d+)$/m', file_get_contents('/proc/self/status'), $match);
        Swoole\Process::kill((int) $match[1], 15);
    }
    unlink($marker);
} elseif ($command === 'logs') {
    if ($mode === 'cleanup-budget') {
        sleep(2);
    }
    echo 'retained fixture log';
}
