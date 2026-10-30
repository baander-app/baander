<?php

declare(strict_types=1);

global $argv;

require dirname(__DIR__, 3) . '/bootstrap.php';

$payload = new Baander\Tooling\Development\ControllerPayload($argv[1]);
$barrier = $payload->drainReaders(5);
try {
    echo "drained\n";
    fflush(STDOUT);
    fgets(STDIN);
} finally {
    $barrier->release();
}
