#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
try {
    Baander\Tooling\Runtime\Requirements::check();
    exit(Baander\Tooling\Fixtures\Worker\OperatorQualification::main(
        array_slice($_SERVER['argv'], 1),
        dirname(__DIR__),
    ));
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->getCode() ?: 2);
} catch (Throwable $error) {
    fwrite(STDERR, Baander\Tooling\Fixtures\Runtime::failure($error) . "\n");
    exit(Baander\Tooling\Fixtures\Runtime::status($error));
}
