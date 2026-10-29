#!/usr/bin/env php
<?php

declare(strict_types=1);

$arguments = array_slice($_SERVER['argv'], 1);
file_put_contents(
    getenv('WORKER_RESOURCE_COMMANDS'),
    json_encode($arguments, JSON_THROW_ON_ERROR) . "\n",
    FILE_APPEND | LOCK_EX,
);
if (in_array($arguments[0] ?? '', ['--context', '--host'], true)) {
    $arguments = array_slice($arguments, 2);
}
require __DIR__ . '/worker-resource-state.php';
workerResourceState($arguments);
if (($arguments[0] ?? '') === 'ps') {
    $filter = $arguments[array_search('--filter', $arguments, true) + 1];
    if (str_starts_with($filter, 'name=^/')) {
        fwrite(STDERR, 'inventory fixture failure');
        exit(7);
    }
}
if (($arguments[0] ?? '') === 'logs') {
    echo "retained container log\n";
}
