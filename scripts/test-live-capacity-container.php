#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    Baander\Tooling\Runtime\Requirements::check();
    $frontend = Baander\Tooling\Development\Environment::executable('corepack')
        ?? throw new RuntimeException('Corepack is required to run the existing frontend browser runner.');
    exit(Baander\Tooling\Fixtures\Web\CapacityWorkflow::main(dirname(__DIR__), $frontend));
} catch (Throwable $error) {
    fwrite(STDERR, Baander\Tooling\Fixtures\Runtime::failure($error) . "\n");
    exit(Baander\Tooling\Fixtures\Runtime::status($error));
}
