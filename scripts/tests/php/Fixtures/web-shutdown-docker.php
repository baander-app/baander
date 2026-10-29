#!/usr/bin/env php
<?php

declare(strict_types=1);

$args = array_slice($_SERVER['argv'] ?? [], 1);
file_put_contents(getenv('WEB_WORKFLOW_TRACE'), json_encode($args, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
while (in_array($args[0] ?? '', ['--context', '--host'], true)) {
    $args = array_slice($args, 2);
}
if ($args === ['context', 'show']) {
    echo "web-fixture\n";
    exit(0);
}
require dirname(__DIR__, 4) . '/vendor/autoload.php';
webDockerState($args, getenv('WEB_WORKFLOW_TRACE'));
if (($args[0] ?? null) === 'start' && str_ends_with(end($args), '-client')) {
    if (getenv('WEB_WORKFLOW_STAGE') === '1') {
        exit(0);
    }
    if (getenv('WEB_WORKFLOW_HANG') === '1') {
        file_put_contents(getenv('WEB_WORKFLOW_READY'), 'ready');
        for (;;) {
            usleep(10000);
        }
    }
    fwrite(STDERR, "injected client start failure\n");
    exit(42);
}
if (($args[0] ?? null) === 'start' && str_ends_with(end($args), '-probe') && getenv('WEB_WORKFLOW_STAGE') === '1') {
    fwrite(STDERR, "injected probe start failure\n");
    exit(43);
}
if (($args[0] ?? null) === 'inspect') {
    exit(1);
}
if (($args[0] ?? null) === 'cp' && in_array('-', $args, true)) {
    stream_get_contents(STDIN);
}
exit(0);
