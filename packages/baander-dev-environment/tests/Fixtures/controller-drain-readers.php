<?php

declare(strict_types=1);

use Baander\DevEnvironment\Controller\ControllerPayload;

global $argv;

require dirname(__DIR__, 4) . '/scripts/bootstrap.php';

$payload = new ControllerPayload($argv[1]);
$barrier = $payload->drainReaders(5);
try {
    echo "drained\n";
    fflush(STDOUT);
    fgets(STDIN);
} finally {
    $barrier->release();
}
