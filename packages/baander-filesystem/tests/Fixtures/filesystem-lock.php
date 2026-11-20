<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/scripts/bootstrap.php';

$argv = $_SERVER['argv'];

echo Baander\Filesystem\AtomicFile::readJson($argv[1] . '/state.json')['phase'] . "\n";
try {
    $lock = Baander\Filesystem\FileLock::acquire($argv[1] . '/operation.lock');
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(23);
}
