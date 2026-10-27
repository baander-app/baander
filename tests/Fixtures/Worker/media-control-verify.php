#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/scripts/bootstrap.php';
try {
    Baander\Tooling\Runtime\Requirements::check(false);
    exit(Baander\Tooling\Fixtures\Worker\MediaControlVerifier::main(array_slice($_SERVER['argv'], 1)));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
