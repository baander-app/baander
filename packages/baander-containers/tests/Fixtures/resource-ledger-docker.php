#!/usr/bin/env php
<?php

declare(strict_types=1);

$arguments = array_slice($_SERVER['argv'], 3);
$state = getenv('RESOURCE_TEST_STATE');
file_put_contents($state . '/calls.jsonl', json_encode($arguments) . "\n", FILE_APPEND);
$command = $arguments[0] ?? '';
$path = static fn(string $name): string => $state . ($name === 'baander-test-owned' ? '/resource.json' : '/resource-' . $name . '.json');
if ($command === 'info') {
    echo json_encode(['ID' => is_file($state . '/wrong-daemon') ? 'foreign-daemon' : 'owned-daemon']);
} elseif ($command === 'create' || in_array($command, ['network', 'volume'], true) && ($arguments[1] ?? '') === 'create') {
    if (!is_file($state . '/receipt.json')) {
        exit(80);
    }
    $kind = $command === 'create' ? 'container' : $command;
    $name = $kind === 'container' ? $arguments[array_search('--name', $arguments, true) + 1] : end($arguments);
    [$key, $value] = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
    $id = $kind === 'volume' ? $name : hash('sha256', $name);
    file_put_contents($path($name), json_encode(['Id' => $id, 'Name' => ($kind === 'container' ? '/' : '') . $name, 'Config' => ['Labels' => [$key => $value]], 'Labels' => [$key => $value], 'kind' => $kind]));
    if (is_file($state . '/fail-create')) {
        exit(9);
    }
    echo $id . "\n";
} elseif (in_array($command, ['container', 'network', 'volume'], true) && ($arguments[1] ?? '') === 'inspect') {
    if (is_file($state . '/inspect-fails')) {
        fwrite(STDERR, 'authorization denied');
        exit(1);
    }
    $name = end($arguments);
    $found = null;
    foreach (glob($state . '/resource*.json') as $file) {
        $resource = json_decode(file_get_contents($file), true);
        if ($resource['kind'] === $command && ($resource['Id'] === $name || ltrim($resource['Name'], '/') === $name)) {
            $found = $resource;
            break;
        }
    }
    if ($found === null) {
        fwrite(STDERR, $command === 'network' ? 'Error response from daemon: network ' . $name . ' not found' : 'Error: No such ' . $command . ': ' . $name);
        exit(1);
    }
    echo json_encode([$found]);
} elseif (in_array($command, ['container', 'network', 'volume'], true) && ($arguments[1] ?? '') === 'ls') {
    $format = $arguments[array_search('--format', $arguments, true) + 1];
    if ($command === 'container' && is_file($state . '/snapshot-size-fails') && ($format === '{{json .}}' || str_contains($format, '.Size'))) {
        fwrite(STDERR, 'snapshotter.Usage failed for unrelated container');
        exit(7);
    }
    if (is_file($state . '/list-fails')) {
        fwrite(STDERR, 'transport unavailable');
        exit(7);
    }
    foreach (glob($state . '/resource*.json') as $file) {
        $resource = json_decode(file_get_contents($file), true);
        if ($resource['kind'] === $command) {
            echo json_encode(['ID' => $resource['Id'], $command === 'container' ? 'Names' : 'Name' => ltrim($resource['Name'], '/')]) . "\n";
        }
    }
} elseif ($command === 'rm' || in_array($command, ['network', 'volume'], true) && ($arguments[1] ?? '') === 'rm') {
    $id = end($arguments);
    foreach (glob($state . '/resource*.json') as $file) {
        $resource = json_decode(file_get_contents($file), true);
        if ($resource['Id'] !== $id) {
            continue;
        }
        if ($command === 'network' && is_file($state . '/resource.json')) {
            fwrite(STDERR, 'network in use');
            exit(5);
        }
        if (is_file($state . '/fail-remove') && $resource['Name'] === '/baander-test-owned') {
            fwrite(STDERR, 'removal denied');
            exit(8);
        }
        unlink($file);
    }
} else {
    fwrite(STDERR, 'Unexpected ledger command');
    exit(79);
}
