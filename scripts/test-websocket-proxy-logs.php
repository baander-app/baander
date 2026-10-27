#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    Baander\Tooling\Runtime\Requirements::check();
    exit(Baander\Tooling\Fixtures\Http\ProxyQualification::main(array_slice($_SERVER['argv'], 1), dirname(__DIR__)));
} catch (Baander\Tooling\Fixtures\Http\DrillFailure $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->status);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
