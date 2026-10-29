#!/usr/bin/env php
<?php

declare(strict_types=1);

$arguments = array_slice($_SERVER['argv'], 1);
if (getenv('WORKER_OPERATOR_TRANSPORT_CALLS') !== false) {
    file_put_contents(getenv('WORKER_OPERATOR_TRANSPORT_CALLS'), json_encode($arguments, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
}
if (in_array($arguments[0] ?? '', ['--context', '--host'], true)) {
    $arguments = array_slice($arguments, 2);
}
file_put_contents(getenv('WORKER_OPERATOR_NETWORK_CALLS'), json_encode($arguments, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if ($arguments[0] === 'info') {
    echo json_encode(['ID' => 'fixture-daemon', 'NCPU' => 4, 'MemTotal' => 32 * 1024 ** 3, 'CgroupVersion' => '2'], JSON_THROW_ON_ERROR);
} elseif (array_slice($arguments, 0, 2) === ['image', 'inspect']) {
    $history = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file(getenv('WORKER_OPERATOR_NETWORK_CALLS'), FILE_IGNORE_NEW_LINES));
    $name = $arguments[array_key_last($arguments)];
    $build = array_find(array_reverse($history), static fn(array $call): bool => $call[0] === 'build'
        && $call[array_search('--tag', $call, true) + 1] === $name);
    $label = $build === null ? null : $build[array_search('--label', $build, true) + 1];
    $labels = ['org.baander.application-layout' => '{"version":1,"root":"/fixture/application"}'];
    if ($label !== null) {
        $labels['org.baander.tooling.run'] = explode('=', $label, 2)[1];
    }
    $id = 'sha256:' . str_repeat($build === null ? 'a' : 'd', 64);
    $removed = array_any($history, static fn(array $call): bool => array_slice($call, 0, 2) === ['image', 'rm'] && in_array($name, $call, true));
    if ($removed) {
        fwrite(STDERR, 'Error response from daemon: No such image: ' . $name);
        exit(1);
    }
    if (!in_array('--format', $arguments, true)) {
        echo json_encode([['Id' => $id, 'Config' => ['Labels' => $labels, 'Env' => ['APP_HOME=/fixture/application'], 'WorkingDir' => '/fixture/application']]], JSON_THROW_ON_ERROR);
    } else {
        echo str_contains(implode(' ', $arguments), '.Config "Volumes"') ? 'null' : $id;
    }
} elseif ($arguments[0] === 'create') {
    if (array_any($arguments, static fn(string $value): bool => str_ends_with($value, '-postgres'))) {
        exit(73);
    }
    echo str_repeat('b', 64);
} elseif ($arguments[0] === 'start') {
    echo in_array('--attach', $arguments, true) ? '[0,1,2,3]' : str_repeat('b', 64);
} elseif (($arguments[1] ?? '') === 'create') {
    echo $arguments[0] === 'volume' ? $arguments[array_key_last($arguments)] : str_repeat('c', 64);
} elseif ($arguments[0] === 'inspect' && in_array('{{.State.ExitCode}}', $arguments, true)) {
    echo '0';
} elseif ($arguments[0] === 'inspect' && in_array('{{.Id}}', $arguments, true)) {
    echo str_repeat('b', 64);
} elseif (($arguments[1] ?? '') === 'inspect' && (in_array('{{.Id}}', $arguments, true) || in_array('{{.Name}}', $arguments, true))) {
    echo str_repeat('c', 64);
} elseif (($arguments[1] ?? '') === 'inspect') {
    fwrite(STDERR, 'Error: No such ' . $arguments[0] . ': ' . $arguments[array_key_last($arguments)]);
    exit(1);
}
