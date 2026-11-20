#!/usr/bin/env php
<?php

declare(strict_types=1);

$argv = $_SERVER['argv'];
if (($argv[1] ?? '') === '--host') {
    array_splice($argv, 1, 2);
}
$state = getenv('INVENTORY_DOCKER_STATE');
file_put_contents($state . '/calls.jsonl', json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if (array_slice($argv, 1, 2) === ['context', 'show']) {
    echo 'default';
} elseif (array_slice($argv, 1, 2) === ['context', 'inspect']) {
    echo json_encode([['Endpoints' => ['docker' => ['Host' => 'unix:///baander.app/docker.sock']]]]);
} elseif (($argv[1] ?? '') === 'info') {
    echo json_encode(['ID' => 'inventory-daemon']);
} elseif (array_slice($argv, 1, 2) === ['container', 'inspect']) {
    if (!is_file($state . '/container.json')) {
        fwrite(STDERR, 'Error: No such container: ' . end($argv));
        exit(1);
    }
    echo '[' . file_get_contents($state . '/container.json') . ']';
} elseif (array_slice($argv, 1, 2) === ['container', 'ls']) {
    if (is_file($state . '/container.json')) {
        echo json_encode(['Names' => json_decode(file_get_contents($state . '/container.json'), true)['Name']]);
    }
} elseif (array_slice($argv, 1, 2) === ['image', 'inspect']) {
    echo file_get_contents($state . '/inspect.json');
} elseif (($argv[1] ?? '') === 'create') {
    $name = $argv[array_search('--name', $argv, true) + 1];
    [$key, $value] = explode('=', $argv[array_search('--label', $argv, true) + 1], 2);
    file_put_contents($state . '/container.json', json_encode(['Id' => str_repeat('a', 64), 'Name' => '/' . $name, 'Config' => ['Labels' => [$key => $value]]]));
    if (is_file($state . '/block-create-log')) {
        file_put_contents($state . '/create-block-ready', 'ready');
        $deadline = microtime(true) + 10;
        while (!is_file($state . '/release-create') && microtime(true) < $deadline) {
            usleep(10000);
        }
    }
    echo str_repeat('a', 64) . "\n";
} elseif (($argv[1] ?? '') === 'export') {
    if (is_file($state . '/pause-export')) {
        file_put_contents($state . '/export-ready', 'ready');
        $deadline = microtime(true) + 10;
        while (!is_file($state . '/release-export') && microtime(true) < $deadline) {
            usleep(10000);
        }
    }
    if (is_file($state . '/fail-export')) {
        fwrite(STDERR, "Controlled export failure\n");
        exit(9);
    }
    copy($state . '/export.tar', $argv[3]);
} elseif (($argv[1] ?? '') === 'rm') {
    if (is_file($state . '/fail-remove')) {
        fwrite(STDERR, 'Controlled removal failure');
        exit(12);
    }
    unlink($state . '/container.json');
    echo str_repeat('a', 64) . "\n";
} else {
    fwrite(STDERR, 'Unexpected Docker command');
    exit(7);
}
