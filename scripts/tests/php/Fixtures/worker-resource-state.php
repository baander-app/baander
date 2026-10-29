<?php

declare(strict_types=1);

/** @param list<string> $arguments */
function workerResourceState(array $arguments): void
{
    $command = $arguments[0] ?? '';
    if ($command === 'context') {
        echo ($arguments[1] ?? '') === 'inspect'
            ? json_encode([['Endpoints' => ['docker' => ['Host' => 'unix:///fixture.sock']]]], JSON_THROW_ON_ERROR)
            : 'fixture';
        exit(0);
    }
    if ($command === 'info') {
        echo json_encode(['ID' => 'fixture-daemon']);
        exit(0);
    }
    if ($command === 'image') {
        if (in_array('--format', $arguments, true)) {
            echo 'sha256:' . str_repeat('a', 64);
        } else {
            echo json_encode([['Config' => ['Labels' => ['org.baander.application-layout' => json_encode(['version' => 1, 'root' => '/fixture/application'])], 'Env' => ['APP_HOME=/fixture/application'], 'WorkingDir' => '/fixture/application']]], JSON_THROW_ON_ERROR);
        }
        exit(0);
    }
    $stateDirectory = dirname(getenv('WORKER_RESOURCE_COMMANDS') ?: getenv('WORKER_LEGACY_COMMANDS'));
    $path = static fn(string $name): string => $stateDirectory . '/resource-' . $name . '.json';
    if ($command === 'create' || in_array($command, ['network', 'volume'], true) && ($arguments[1] ?? '') === 'create') {
        $kind = $command === 'create' ? 'container' : $command;
        $name = $kind === 'container' ? $arguments[array_search('--name', $arguments, true) + 1] : end($arguments);
        [$key, $value] = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
        $id = $kind === 'volume' ? $name : hash('sha256', $name);
        file_put_contents($path($name), json_encode(['Id' => $id, 'Name' => ($kind === 'container' ? '/' : '') . $name, 'Config' => ['Labels' => [$key => $value]], 'Labels' => [$key => $value], 'kind' => $kind]));
        echo $id;
        exit(0);
    }
    if (in_array($command, ['container', 'network', 'volume'], true) && ($arguments[1] ?? '') === 'inspect') {
        $name = end($arguments);
        if (getenv('WORKER_RESOURCE_COMMANDS') && str_ends_with($name, '-blocked')) {
            fwrite(STDERR, 'inventory fixture failure');
            exit(7);
        }
        if (!is_file($path($name))) {
            fwrite(STDERR, 'Error: No such ' . $command . ': ' . $name);
            exit(1);
        }
        echo '[' . file_get_contents($path($name)) . ']';
        exit(0);
    }
    if ($command === 'rm' || in_array($command, ['network', 'volume'], true) && ($arguments[1] ?? '') === 'rm') {
        foreach (glob($stateDirectory . '/resource-*.json') as $file) {
            $resource = json_decode(file_get_contents($file), true);
            if ($resource['Id'] === end($arguments)) {
                unlink($file);
            }
        }
        exit(0);
    }
    if (in_array($command, ['container', 'network', 'volume'], true) && ($arguments[1] ?? '') === 'ls') {
        foreach (glob($stateDirectory . '/resource-*.json') as $file) {
            $resource = json_decode(file_get_contents($file), true);
            if ($resource['kind'] === $command) {
                echo json_encode(['ID' => $resource['Id'], $command === 'container' ? 'Names' : 'Name' => ltrim($resource['Name'], '/')]) . "\n";
            }
        }
        exit(0);
    }

}
