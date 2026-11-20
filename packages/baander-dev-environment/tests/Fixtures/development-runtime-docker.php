#!/usr/bin/env php
<?php

declare(strict_types=1);

$directory = dirname($_SERVER['argv'][0]);
$arguments = array_slice($_SERVER['argv'], 3);
file_put_contents($directory . '/runtime-resource-calls.jsonl', json_encode($arguments, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
$command = $arguments[0] ?? '';
$files = glob($directory . '/runtime-resource-*.json');
if ($command === 'info') {
    echo '{"ID":"daemon"}';
} elseif ($command === 'create') {
    $name = $arguments[array_search('--name', $arguments, true) + 1];
    [$key, $value] = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
    $resource = ['Id' => hash('sha256', $name), 'Name' => '/' . $name, 'State' => ['Running' => false, 'ExitCode' => 0], 'Config' => ['Labels' => [$key => $value]]];
    file_put_contents($directory . '/runtime-resource-' . $name . '.json', json_encode($resource, JSON_THROW_ON_ERROR));
    if (is_file($directory . '/runtime-fail-create')) {
        exit(9);
    }
    echo $resource['Id'];
} elseif ($command === 'start') {
    if (is_file($directory . '/runtime-fail-start')) {
        exit(7);
    }
    echo '{}';
} elseif ($command === 'inspect' && in_array('--format', $arguments, true)) {
    echo in_array('{{.State.Running}}', $arguments, true) ? 'false' : (is_file($directory . '/runtime-container-exit') ? file_get_contents($directory . '/runtime-container-exit') : '0');
} elseif ($command === 'container' && ($arguments[1] ?? '') === 'inspect') {
    $path = $directory . '/runtime-resource-' . end($arguments) . '.json';
    if (!is_file($path)) {
        fwrite(STDERR, 'Error: No such container: ' . end($arguments));
        exit(1);
    }
    echo '[' . file_get_contents($path) . ']';
} elseif ($command === 'container' && ($arguments[1] ?? '') === 'ls') {
    foreach ($files as $file) {
        $resource = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        echo json_encode(['Names' => ltrim($resource['Name'], '/')], JSON_THROW_ON_ERROR) . "\n";
    }
} elseif ($command === 'rm') {
    foreach ($files as $file) {
        $resource = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (end($arguments) !== $resource['Id']) {
            continue;
        }
        if (is_file($directory . '/runtime-fail-remove')) {
            fwrite(STDERR, 'removal denied');
            exit(8);
        }
        unlink($file);
    }
} else {
    fwrite(STDERR, 'Unexpected runtime resource command');
    exit(79);
}
