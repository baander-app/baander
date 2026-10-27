#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

try {
    Baander\Tooling\Runtime\Requirements::check(extensions: ['FFI']);
    exit(Baander\Tooling\Fixtures\Http\OrphanSupervisor::main(array_slice($_SERVER['argv'], 1)));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
