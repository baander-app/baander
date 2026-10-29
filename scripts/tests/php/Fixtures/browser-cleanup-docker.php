#!/usr/bin/env php
<?php

declare(strict_types=1);

$scenario = getenv('CLEANUP_TEST_SCENARIO');
$removed = (string) getenv('CLEANUP_TEST_REMOVED');
if (getenv('DOCKER_HOST') !== false || getenv('DOCKER_CONTEXT') !== false) {
    exit(97);
}
$args = array_slice($argv ?? [], 1);
if (array_splice($args, 0, 2) !== ['--host', 'unix:///captured.sock']) {
    exit(98);
}
if ($args[0] === 'info') {
    if ($scenario === 'daemon-failure') {
        exit(1);
    }
    echo json_encode(['ID' => 'test-daemon']);
} elseif (array_slice($args, 0, 2) === ['container', 'inspect']) {
    if ($scenario === 'never-created' || file_exists($removed)) {
        fwrite(STDERR, 'Error: No such container: ' . end($args) . "\n");
        exit(1);
    }
    echo json_encode([['Id' => str_repeat('b', 64), 'Name' => '/baander-browser-cleanup-test', 'Config' => ['Labels' => ['org.baander.tooling.run' => str_repeat('a', 32)]]]]);
} elseif (array_slice($args, 0, 2) === ['container', 'ls']) {
    if ($scenario !== 'never-created' && !file_exists($removed)) {
        echo json_encode(['ID' => str_repeat('b', 64), 'Names' => 'baander-browser-cleanup-test']) . "\n";
    }
} elseif ($args[0] === 'rm') {
    if ($scenario === 'survivor') {
        exit(1);
    }
    touch($removed);
} elseif ($args[0] === 'cp') {
    if ($scenario === 'never-created') {
        fwrite(STDERR, "Error: No such container: baander-browser-cleanup-test\n");
        exit(1);
    }
    exit($scenario === 'copy-failure' ? 1 : 0);
} else {
    exit(99);
}
