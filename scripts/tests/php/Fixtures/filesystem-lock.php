<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

$argv = $_SERVER['argv'];

echo Baander\Tooling\Filesystem\AtomicFile::readJson($argv[1] . '/state.json')['phase'] . "\n";
try {
    $lock = Baander\Tooling\Filesystem\FileLock::acquire($argv[1] . '/operation.lock');
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(23);
}
