<?php

declare(strict_types=1);

use Baander\Tooling\Dependencies\Command;
use Baander\Tooling\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

try {
    Requirements::check();
    exit(Command::main(array_slice($argv ?? [], 1), dirname(__DIR__), getenv()));
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(2);
}
