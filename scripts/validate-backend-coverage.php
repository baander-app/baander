#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevCi\Backend\Coverage;
use Baander\Process\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

try {
    Requirements::check(extensions: ['dom']);
    exit(Coverage::main(array_slice($_SERVER['argv'], 1)));
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
