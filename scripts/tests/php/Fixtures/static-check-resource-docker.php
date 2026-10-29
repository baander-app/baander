#!/usr/bin/env php
<?php

declare(strict_types=1);
$arguments = array_slice($_SERVER['argv'], 3);
$state = getenv('RESOURCE_TEST_STATE');
file_put_contents($state . '/calls.jsonl', json_encode($arguments) . "\n", FILE_APPEND);
$command = $arguments[0] ?? '';
if ($command === 'image' && ($arguments[1] ?? '') === 'inspect') {
    $root = is_file($state . '/wrong-layout') ? '/srv/baander' : '/var/www/html';
    echo json_encode([['Config' => ['Labels' => ['org.baander.application-layout' => json_encode(['version' => 1, 'root' => $root])], 'Env' => ['APP_HOME=' . $root], 'WorkingDir' => $root]]]);
} elseif ($command === 'info') {
    echo json_encode(['ID' => is_file($state . '/wrong-daemon') ? 'foreign-daemon' : 'owned-daemon']);
} elseif ($command === 'create') {
    if (!is_file($state . '/receipt.json')) {
        exit(80);
    }
    $name = $arguments[array_search('--name', $arguments, true) + 1];
    [$key, $value] = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
    file_put_contents($state . '/resource.json', json_encode(['Id' => str_repeat('a', 64), 'Name' => '/' . $name, 'Config' => ['Labels' => [$key => $value]]]));
    if (is_file($state . '/fail-create')) {
        exit(9);
    }
    echo str_repeat('a', 64) . "\n";
} elseif ($command === 'container' && ($arguments[1] ?? '') === 'inspect') {
    if (is_file($state . '/inspect-fails')) {
        fwrite(STDERR, 'authorization denied');
        exit(1);
    }
    if (!is_file($state . '/resource.json')) {
        fwrite(STDERR, 'Error: No such container: ' . end($arguments));
        exit(1);
    }
    echo '[' . file_get_contents($state . '/resource.json') . ']';
} elseif ($command === 'container' && ($arguments[1] ?? '') === 'ls') {
    if (is_file($state . '/resource.json')) {
        echo json_encode(['Names' => 'baander-test-owned']);
    }
} elseif ($command === 'start') {
} elseif ($command === 'cp') {
    if (is_file($state . '/fail-copy')) {
        fwrite(STDERR, 'copy failed');
        exit(19);
    }
} elseif ($command === 'exec') {
    if (($arguments[1] ?? '') === '--user') {
        exit(is_file($state . '/check-success') ? 0 : 7);
    }
} elseif ($command === 'rm') {
    if (is_file($state . '/fail-remove')) {
        fwrite(STDERR, 'removal denied');
        exit(8);
    }
    unlink($state . '/resource.json');
} else {
    fwrite(STDERR, 'Unexpected ledger command');
    exit(79);
}
