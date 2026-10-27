#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Fixtures\Web\ShutdownWorkflow;
use Baander\Tooling\Runtime\Requirements;

try {
    Requirements::check();
    new ShutdownWorkflow(dirname(__DIR__))->run();
} catch (Throwable $failure) {
    fwrite(STDERR, Runtime::failure($failure) . "\n");
    exit(Runtime::status($failure));
}
