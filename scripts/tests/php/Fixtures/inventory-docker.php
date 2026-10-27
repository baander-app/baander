#!/usr/bin/env php
<?php

declare(strict_types=1);

$argv = $_SERVER['argv'];
$state = getenv('INVENTORY_DOCKER_STATE');
file_put_contents($state . '/calls.jsonl', json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if (array_slice($argv, 1, 2) === ['image', 'inspect']) {
    echo file_get_contents($state . '/inspect.json');
} elseif (($argv[1] ?? '') === 'create') {
    if (is_file($state . '/block-create-log')) {
        $log = $state . '/bundle/collection.log';
        unlink($log);
        $fifo = proc_open(
            ['mkfifo', $log],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($fifo) || proc_close($fifo) !== 0) {
            exit(8);
        }
        preg_match('/^PPid:\s+(\d+)/m', (string) file_get_contents('/proc/self/status'), $parent);
        file_put_contents($state . '/collector-pid', $parent[1]);
        file_put_contents($state . '/create-block-ready', 'ready');
    }
    echo "inspection-container\n";
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
    echo "inspection-container\n";
} else {
    fwrite(STDERR, 'Unexpected Docker command');
    exit(7);
}
