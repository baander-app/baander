#!/usr/bin/env php
<?php

declare(strict_types=1);

$directory = dirname($_SERVER['argv'][0]);
$arguments = array_slice($_SERVER['argv'], 3);
file_put_contents($directory . '/resource-calls.jsonl', json_encode($arguments, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
$command = $arguments[0] ?? '';
if ($command === 'info') {
    echo '{"ID":"daemon"}';
} elseif ($command === 'create') {
    $name = $arguments[array_search('--name', $arguments, true) + 1];
    [$key, $value] = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
    $resource = ['Id' => hash('sha256', $name), 'Name' => '/' . $name, 'Config' => ['Labels' => [$key => $value]]];
    file_put_contents($directory . '/resource.json', json_encode($resource, JSON_THROW_ON_ERROR));
    if (is_file($directory . '/fail-create')) {
        exit(9);
    }
    echo $resource['Id'];
} elseif ($command === 'container' && ($arguments[1] ?? '') === 'inspect') {
    if (!is_file($directory . '/resource.json')) {
        fwrite(STDERR, 'Error: No such container: ' . end($arguments));
        exit(1);
    }
    echo '[' . file_get_contents($directory . '/resource.json') . ']';
} elseif ($command === 'container' && ($arguments[1] ?? '') === 'ls') {
    if (is_file($directory . '/resource.json')) {
        $resource = json_decode(file_get_contents($directory . '/resource.json'), true, flags: JSON_THROW_ON_ERROR);
        echo json_encode(['Names' => ltrim($resource['Name'], '/')], JSON_THROW_ON_ERROR);
    }
} elseif ($command === 'rm') {
    $resource = json_decode(file_get_contents($directory . '/resource.json'), true, flags: JSON_THROW_ON_ERROR);
    if (end($arguments) !== $resource['Id']) {
        exit(8);
    }
    if (is_file($directory . '/fail-remove')) {
        fwrite(STDERR, 'removal denied');
        exit(8);
    }
    unlink($directory . '/resource.json');
} else {
    fwrite(STDERR, 'Unexpected image resource command');
    exit(79);
}
