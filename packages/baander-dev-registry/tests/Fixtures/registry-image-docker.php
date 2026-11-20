#!/usr/bin/env php
<?php

declare(strict_types=1);

$directory = getenv('BAANDER_REGISTRY_IMAGE_FIXTURE');
$arguments = array_slice($_SERVER['argv'], 1);
file_put_contents($directory . '/commands.jsonl', json_encode($arguments, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if ($arguments === ['context', 'inspect', 'initial']) {
    $endpoint = file_exists($directory . '/context') ? 'changed' : 'initial';
    echo json_encode([['Endpoints' => ['docker' => ['Host' => 'unix:///tmp/' . $endpoint . '.sock']]]], JSON_THROW_ON_ERROR);
    exit(0);
}
if (($arguments[0] ?? '') !== '--host' || ($arguments[1] ?? '') !== 'unix:///tmp/initial.sock') {
    fwrite(STDERR, "Unexpected Docker target.\n");
    exit(98);
}
$arguments = array_slice($arguments, 2);
if (($arguments[0] ?? '') === 'info') {
    echo '{"ID":"fixture-daemon"}';
    exit(0);
}
if (($arguments[0] ?? '') === 'image') {
    file_put_contents($directory . '/context', 'changed');
    echo 'sha256:' . str_repeat('a', 64);
    exit(0);
}
if (($arguments[0] ?? '') === 'create') {
    file_put_contents($directory . '/resource.json', json_encode([
        'Id' => str_repeat('b', 64),
        'Name' => '/' . $arguments[array_search('--name', $arguments, true) + 1],
        'Config' => ['Labels' => ['org.baander.tooling.run' => substr($arguments[array_search('--label', $arguments, true) + 1], strlen('org.baander.tooling.run='))]],
    ], JSON_THROW_ON_ERROR));
    fwrite(STDERR, "fixture create failed\n");
    exit(23);
}
if (($arguments[0] ?? '') === 'container' && ($arguments[1] ?? '') === 'inspect') {
    echo '[' . file_get_contents($directory . '/resource.json') . ']';
    exit(0);
}
fwrite(STDERR, "fixture removal unavailable\n");
exit(24);
