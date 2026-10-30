#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    Baander\Tooling\Runtime\Requirements::check();
    $arguments = $_SERVER['argv'];
    if (count($arguments) !== 2) {
        throw new InvalidArgumentException('Usage: php scripts/test-live-capacity-exec.php MANIFEST');
    }
    exit(Baander\Tooling\Fixtures\Web\CapacityWorkflow::executeFixture(dirname(__DIR__), $arguments[1]));
} catch (Throwable $error) {
    fwrite(STDERR, Baander\Tooling\Fixtures\Runtime::failure($error) . "\n");
    exit(Baander\Tooling\Fixtures\Runtime::status($error));
}
