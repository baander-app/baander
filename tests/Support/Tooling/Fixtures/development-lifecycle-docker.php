#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname($_SERVER['SCRIPT_FILENAME']);
$configuration = json_decode(file_get_contents($root . '/daemon.json'), true, flags: JSON_THROW_ON_ERROR);
$arguments = array_slice($_SERVER['argv'], 3);
file_put_contents($root . '/calls.jsonl', json_encode($arguments, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
$resources = glob($root . '/resource-*.json') ?: [];
$lookup = static function (string $name) use ($resources): ?array {
    foreach ($resources as $file) {
        $resource = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (in_array($name, [$resource['Id'], ltrim($resource['Name'], '/')], true)) {
            return [$file, $resource];
        }
    }
    return null;
};
if (($arguments[0] ?? null) === 'info') {
    echo json_encode(['ID' => $configuration['daemonId'] ?? 'owned-daemon', 'NCPU' => 8, 'MemTotal' => 16 * 1024 ** 3], JSON_THROW_ON_ERROR);
} elseif (($arguments[0] ?? null) === 'create') {
    $name = $arguments[array_search('--name', $arguments, true) + 1];
    [$key, $value] = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
    $id = hash('sha256', $name);
    file_put_contents($root . '/resource-' . $id . '.json', json_encode([
        'Id' => $id, 'Name' => '/' . $name, 'Config' => ['Labels' => [$key => $value]],
        'arguments' => $arguments, 'State' => ['ExitCode' => $configuration['exitCode'] ?? 0],
    ], JSON_THROW_ON_ERROR));
    if ($configuration['failCreate'] ?? false) {
        fwrite(STDERR, 'Creation reply lost');
        exit(23);
    }
    echo $id;
} elseif (($arguments[0] ?? null) === 'start') {
    $resource = $lookup($arguments[array_key_last($arguments)]);
    if ($resource === null) {
        exit(1);
    }
    $input = stream_get_contents(STDIN);
    file_put_contents($root . '/input', $input);
    echo $configuration['stdout'] ?? '';
    fwrite(STDERR, $configuration['stderr'] ?? '');
} elseif (($arguments[0] ?? null) === 'inspect' && in_array('--format', $arguments, true)) {
    $resource = $lookup($arguments[array_key_last($arguments)]);
    if ($resource === null) {
        exit(1);
    }
    echo $resource[1]['State']['ExitCode'];
} elseif (($arguments[0] ?? null) === 'container' && ($arguments[1] ?? null) === 'inspect') {
    $name = $arguments[array_key_last($arguments)];
    $resource = $lookup($name);
    if ($resource === null) {
        fwrite(STDERR, 'Error: No such container: ' . $name);
        exit(1);
    }
    echo json_encode([$resource[1]], JSON_THROW_ON_ERROR);
} elseif (($arguments[0] ?? null) === 'container' && ($arguments[1] ?? null) === 'ls') {
    foreach ($resources as $file) {
        $resource = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        echo json_encode(['ID' => $resource['Id'], 'Names' => ltrim($resource['Name'], '/')], JSON_THROW_ON_ERROR), "\n";
    }
} elseif (($arguments[0] ?? null) === 'rm') {
    if ($configuration['failRemove'] ?? false) {
        fwrite(STDERR, 'Removal unavailable');
        exit(27);
    }
    $resource = $lookup($arguments[array_key_last($arguments)]);
    if ($resource !== null) {
        unlink($resource[0]);
    }
} else {
    fwrite(STDERR, 'Unexpected lifecycle fixture command: ' . implode(' ', $arguments));
    exit(1);
}
