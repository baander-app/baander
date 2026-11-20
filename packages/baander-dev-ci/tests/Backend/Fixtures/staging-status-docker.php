#!/usr/bin/env php
<?php

declare(strict_types=1);

$arguments = array_slice($_SERVER['argv'], 3);
$state = getenv('STAGING_STATUS_STATE');
$command = $arguments[0] ?? '';
if ($command === 'info') {
    echo json_encode(['ID' => 'staging-status-daemon']);
} elseif ($command === 'image') {
    echo json_encode([['Config' => ['Labels' => ['org.baander.application-layout' => '{"version":1,"root":"/var/www/html"}'], 'Env' => ['APP_HOME=/var/www/html'], 'WorkingDir' => '/var/www/html']]]);
} elseif ($command === 'create') {
    $name = $arguments[array_search('--name', $arguments, true) + 1];
    [$key, $value] = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
    file_put_contents($state . '/container.json', json_encode(['Id' => str_repeat('b', 64), 'Name' => '/' . $name, 'Config' => ['Labels' => [$key => $value]]]));
    echo str_repeat('b', 64);
} elseif ($command === 'container' && $arguments[1] === 'inspect') {
    if (!is_file($state . '/container.json')) {
        fwrite(STDERR, 'Error: No such container: ' . end($arguments));
        exit(1);
    }
    echo '[' . file_get_contents($state . '/container.json') . ']';
} elseif ($command === 'rm') {
    unlink($state . '/container.json');
} elseif ($command === 'exec' && ($arguments[1] ?? '') === '--user') {
    exit(67);
} elseif (!in_array($command, ['start', 'cp', 'exec', 'container'], true)) {
    fwrite(STDERR, 'Unexpected staging status Docker command');
    exit(9);
}
