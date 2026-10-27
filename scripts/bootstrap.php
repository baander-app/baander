<?php

declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Install workflow tooling first: composer --working-dir=scripts install --no-dev');
}
require $autoload;
