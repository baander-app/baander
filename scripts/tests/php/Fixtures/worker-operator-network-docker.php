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
$imagesPath = getenv('WORKER_OPERATOR_NETWORK_IMAGES');
$images = json_decode(file_get_contents($imagesPath), true, flags: JSON_THROW_ON_ERROR);
$findImage = static fn(string $name): ?array => $images[$name] ?? array_find($images, static fn(array $image): bool => $image['Id'] === $name);
if ($arguments[0] === 'info') {
    echo json_encode(['ID' => 'fixture-daemon', 'NCPU' => 4, 'MemTotal' => 32 * 1024 ** 3, 'CgroupVersion' => '2'], JSON_THROW_ON_ERROR);
} elseif (array_slice($arguments, 0, 2) === ['image', 'inspect']) {
    $name = $arguments[array_key_last($arguments)];
    $image = $findImage($name);
    if ($image === null) {
        fwrite(STDERR, 'Error response from daemon: No such image: ' . $name);
        exit(1);
    }
    $image['RepoTags'] = array_keys(array_filter($images, static fn(array $candidate): bool => $candidate['Id'] === $image['Id']));
    $image['RepoDigests'] = [];
    if (!in_array('--format', $arguments, true)) {
        echo json_encode([$image], JSON_THROW_ON_ERROR);
    } else {
        echo str_contains(implode(' ', $arguments), '.Config "Volumes"') ? 'null' : $image['Id'];
    }
} elseif (array_slice($arguments, 0, 2) === ['image', 'tag']) {
    $source = $findImage($arguments[2]);
    if ($source === null || isset($images[$arguments[3]])) {
        fwrite(STDERR, 'Missing tag source or existing destination.');
        exit(1);
    }
    $images[$arguments[3]] = $source;
    file_put_contents($imagesPath, json_encode($images, JSON_THROW_ON_ERROR));
} elseif ($arguments[0] === 'build') {
    $name = $arguments[array_search('--tag', $arguments, true) + 1];
    [$key, $value] = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
    $images[$name] = ['Id' => 'sha256:' . hash('sha256', $name), 'Config' => ['Labels' => [$key => $value]]];
    file_put_contents($imagesPath, json_encode($images, JSON_THROW_ON_ERROR));
} elseif (array_slice($arguments, 0, 2) === ['image', 'rm']) {
    $name = $arguments[array_key_last($arguments)];
    if (str_starts_with($name, 'sha256:')) {
        $images = array_filter($images, static fn(array $image): bool => $image['Id'] !== $name);
    } else {
        unset($images[$name]);
    }
    file_put_contents($imagesPath, json_encode($images, JSON_THROW_ON_ERROR));
} elseif (array_slice($arguments, 0, 2) === ['image', 'ls']) {
    foreach ($images as $name => $image) {
        $filter = array_search('--filter', $arguments, true);
        if ($filter !== false) {
            [$key, $value] = explode('=', substr($arguments[$filter + 1], strlen('label=')), 2);
            if (($image['Config']['Labels'][$key] ?? null) !== $value) {
                continue;
            }
        }
        $colon = strrpos($name, ':');
        echo json_encode(['Repository' => substr($name, 0, $colon), 'Tag' => substr($name, $colon + 1), 'ID' => $image['Id']], JSON_THROW_ON_ERROR) . "\n";
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
