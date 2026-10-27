#!/usr/bin/env php
<?php

declare(strict_types=1);

$arguments = array_slice($_SERVER['argv'], 1);
if (in_array($arguments[0] ?? '', ['--context', '--host'], true)) {
    $arguments = array_slice($arguments, 2);
}
file_put_contents(getenv('WORKER_OPERATOR_NETWORK_CALLS'), json_encode($arguments, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if ($arguments[0] === 'info') {
    echo json_encode(['ID' => 'fixture-daemon', 'NCPU' => 4, 'MemTotal' => 32 * 1024 ** 3, 'CgroupVersion' => '2'], JSON_THROW_ON_ERROR);
} elseif (array_slice($arguments, 0, 2) === ['image', 'inspect']) {
    echo str_contains(implode(' ', $arguments), '.Config "Volumes"') ? 'null' : 'sha256:' . str_repeat('a', 64);
} elseif ($arguments[0] === 'run') {
    if (array_any($arguments, static fn(string $value): bool => str_ends_with($value, '-postgres'))) {
        exit(73);
    }
    echo in_array('-r', $arguments, true) ? '[0,1,2,3]' : str_repeat('b', 64);
}
