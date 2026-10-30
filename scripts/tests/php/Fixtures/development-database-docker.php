<?php

declare(strict_types=1);

$configuration = json_decode(file_get_contents($_SERVER['argv'][1]), true, flags: JSON_THROW_ON_ERROR);
$root = $configuration['root'];
$arguments = array_slice($_SERVER['argv'], 4);
file_put_contents($root . '/database-calls.jsonl', json_encode($arguments, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
$command = $arguments[0] ?? '';
$resources = $root . '/docker-resources';
is_dir($resources) || mkdir($resources, 0700);
$path = static fn(string $name): string => $resources . '/' . hash('sha256', $name) . '.json';
$lookup = static function (string $identity) use ($resources): ?array {
    foreach (glob($resources . '/*.json') as $file) {
        $resource = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if ($resource['Id'] === $identity || ltrim($resource['Name'], '/') === $identity) {
            return $resource;
        }
    }
    return null;
};
if ($command === 'info') {
    echo '{"ID":"daemon"}';
} elseif ($command === 'create' || in_array($command, ['network', 'volume'], true) && ($arguments[1] ?? null) === 'create') {
    $kind = $command === 'create' ? 'container' : $command;
    $name = $kind === 'container' ? $arguments[array_search('--name', $arguments, true) + 1] : end($arguments);
    [$label, $token] = explode('=', $arguments[array_search('--label', $arguments, true) + 1], 2);
    $intended = false;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) {
            continue;
        }
        $receipt = json_decode(file_get_contents($file->getPathname()), true);
        if (($receipt['token'] ?? null) === $token && array_any($receipt['resources'] ?? [], static fn(array $resource): bool => $resource['name'] === $name)) {
            $intended = true;
            break;
        }
    }
    if ($label === 'org.baander.tooling.run' && !$intended) {
        fwrite(STDERR, 'Resource creation preceded durable ownership');
        exit(80);
    }
    if (isset($configuration['instancePath']) && $kind === 'volume' && $label !== 'org.baander.tooling.run') {
        $instance = json_decode(file_get_contents($configuration['instancePath']), true, flags: JSON_THROW_ON_ERROR);
        if (!array_any($instance['adoption_transfer'] ?? [], static fn(array $transfer): bool => $transfer['volume'] === $name && $transfer['phase'] === 'planned')) {
            fwrite(STDERR, 'Data-volume creation preceded transfer intention');
            exit(81);
        }
    }
    if (in_array('app:dev:setup', $arguments, true) && isset($configuration['instancePath'])) {
        $instance = json_decode(file_get_contents($configuration['instancePath']), true, flags: JSON_THROW_ON_ERROR);
        if (($instance['database_mutation_started'] ?? null) !== true) {
            fwrite(STDERR, 'Application mutation preceded durable boundary');
            exit(82);
        }
    }
    $id = $kind === 'volume' ? $name : hash('sha256', $name);
    file_put_contents($path($name), json_encode(['Id' => $id, 'Name' => $kind === 'container' ? '/' . $name : $name,
        'arguments' => $arguments, 'Config' => ['Labels' => [$label => $token]], 'Labels' => [$label => $token], 'kind' => $kind,
        'State' => ['ExitCode' => ($configuration['migrationFailure'] ?? false) && str_contains($name, '-app-') ? 1 : 0, 'Running' => false]], JSON_THROW_ON_ERROR));
    echo $id;
} elseif (in_array($command, ['container', 'network', 'volume'], true) && ($arguments[1] ?? null) === 'inspect') {
    $name = end($arguments);
    $resource = $lookup($name);
    if ($resource === null && (str_starts_with($name, 'existing-') || $name === 'original-transcode')) {
        echo json_encode([['Name' => $name, 'Labels' => []]], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if ($resource === null) {
        fwrite(STDERR, 'Error: No such ' . $command . ': ' . $name);
        exit(1);
    }
    echo json_encode([$resource], JSON_THROW_ON_ERROR);
} elseif (in_array($command, ['container', 'network', 'volume'], true) && ($arguments[1] ?? null) === 'ls') {
    foreach (glob($resources . '/*.json') as $file) {
        $resource = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if ($resource['kind'] === $command) {
            echo json_encode(['ID' => $resource['Id'], $command === 'container' ? 'Names' : 'Name' => ltrim($resource['Name'], '/')], JSON_THROW_ON_ERROR) . "\n";
        }
    }
} elseif ($command === 'rm' || in_array($command, ['network', 'volume'], true) && ($arguments[1] ?? null) === 'rm') {
    foreach (glob($resources . '/*.json') as $file) {
        $resource = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if ($resource['Id'] !== end($arguments)) {
            continue;
        }
        if (is_file($root . '/deny-removal') && $resource['kind'] === 'container' && !str_contains($resource['Name'], '-app-1')) {
            fwrite(STDERR, 'fixture removal denied');
            exit(8);
        }
        unlink($file);
    }
} elseif ($command === 'compose') {
    echo end($arguments) === 'redis' ? 'redis-container' : 'postgres-container';
} elseif ($command === 'image' && ($arguments[1] ?? null) === 'inspect') {
    $applicationRoot = $configuration['applicationRoot'] ?? '/var/www/html';
    echo json_encode([['Id' => 'existing-image', 'Config' => ['WorkingDir' => $applicationRoot,
        'Env' => ['APP_HOME=' . $applicationRoot], 'Labels' => ['org.baander.application-layout' => json_encode(['version' => 1, 'root' => $applicationRoot], JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR);
} elseif ($command === 'inspect') {
    $name = end($arguments);
    if (str_starts_with($name, 'exact-')) {
        $environment = $configuration['adoptionEnvironment'];
        $entries = [];
        $mounts = [];
        if ($name === 'exact-postgres') {
            $entries = ['PGDATA=/var/lib/postgresql/data/baanderdev', 'POSTGRES_PASSWORD=' . $environment['DB_PASSWORD'], 'POSTGRES_USER=baander', 'POSTGRES_DB=baander'];
            $mounts = [['Type' => 'volume', 'Name' => 'existing-database', 'Destination' => '/var/lib/postgresql/data']];
        } elseif ($name === 'exact-redis') {
            $mounts = [['Type' => 'volume', 'Name' => 'existing-redis', 'Destination' => '/data']];
        } else {
            foreach ($environment as $key => $value) {
                $entries[] = $key . '=' . $value;
            }
            $mounts = [['Type' => 'bind', 'Source' => '/original/checkout', 'Destination' => $configuration['applicationRoot'] ?? '/var/www/html'],
                ['Type' => 'bind', 'Source' => '/original/library', 'Destination' => '/storage/media'],
                ['Type' => 'bind', 'Source' => '/original/conversions', 'Destination' => '/storage/conversions'], ...($configuration['extraMounts'] ?? [])];
        }
        echo json_encode([['Id' => $name, 'Image' => 'existing-image', 'Config' => ['Env' => $entries], 'Mounts' => $mounts, 'State' => ['Running' => false]]], JSON_THROW_ON_ERROR);
        exit(0);
    }
    $resource = $lookup($name);
    if (in_array('--format', $arguments, true)) {
        echo (string) ($resource['State']['ExitCode'] ?? 0);
    } elseif ($resource !== null) {
        echo json_encode([$resource], JSON_THROW_ON_ERROR);
    } else {
        echo '[{"Id":"redis-container","State":{"Running":false}}]';
    }
} elseif ($command === 'cp') {
    $target = end($arguments);
    if (!str_contains($target, ':')) {
        file_put_contents($target, 'archive');
    }
} elseif ($command === 'exec') {
    $text = implode(' ', $arguments);
    if (str_contains($text, 'psql')) {
        echo json_encode($configuration['catalog'] + ['writers' => 0], JSON_THROW_ON_ERROR);
    } elseif (str_contains($text, 'CONFIG GET dir')) {
        echo "dir\n/data\n";
    } elseif (str_contains($text, 'redis-cli --raw SAVE')) {
        echo 'OK';
    }
} elseif ($command === 'start') {
    $name = end($arguments);
    $resource = $lookup($name) ?? [];
    $name = ltrim($resource['Name'] ?? $name, '/');
    $migration = array_intersect(['app:dev:setup', 'doctrine:migrations:migrate'], $resource['arguments'] ?? []) !== [];
    if (($configuration['attachedFailure'] ?? false) && in_array('--attach', $arguments, true)
        && ($migration || str_starts_with($name, 'baander-adoption-copy-'))) {
        fwrite(STDERR, 'fixture migration failed after an earlier commit');
        exit(17);
    }
} elseif ($command !== 'stop') {
    fwrite(STDERR, 'Unexpected disposable database fixture command: ' . $command);
    exit(79);
}
