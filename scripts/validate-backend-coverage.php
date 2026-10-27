#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    Baander\Tooling\Runtime\Requirements::check(extensions: ['dom']);
    exit(Baander\Tooling\Backend\Coverage::main(array_slice($_SERVER['argv'], 1)));
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
