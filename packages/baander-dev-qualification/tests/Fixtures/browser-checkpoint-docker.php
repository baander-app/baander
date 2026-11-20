#!/usr/bin/env php
<?php

declare(strict_types=1);

if (getenv('DOCKER_HOST') !== false || getenv('DOCKER_CONTEXT') !== false) {
    exit(97);
}
$arguments = array_slice($_SERVER['argv'], 1);
file_put_contents(getenv('BROWSER_TEST_COMMANDS'), json_encode($arguments) . "\n", FILE_APPEND);
if (array_splice($arguments, 0, 2) !== ['--host', 'unix:///selected.sock']) {
    exit(98);
}
if ($arguments[0] === 'info') {
    echo json_encode(['ID' => 'selected-daemon']);
} elseif ($arguments[0] === 'pull') {
    exit(0);
} elseif (array_slice($arguments, 0, 2) === ['image', 'inspect']) {
    echo 'sha256:' . str_repeat('c', 64);
} elseif ($arguments[0] === 'create') {
    echo str_repeat('b', 64);
} elseif ($arguments[0] === 'exec' && in_array('node', $arguments, true)) {
    echo '{"phase":"completed"}';
    fwrite(STDERR, 'browser diagnostic');
} elseif (!in_array($arguments[0], ['cp', 'start', 'exec'], true)) {
    exit(99);
}
