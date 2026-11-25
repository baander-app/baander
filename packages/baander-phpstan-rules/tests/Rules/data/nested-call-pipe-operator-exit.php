<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\NestedCallPipeOperator;

if ($argc > 2) {
    die(Cli::main(array_slice($argv, 1)));
}

if ($argc > 1) {
    exit(strlen(trim(strtolower($argv[1]))));
}

exit(Cli::main(array_slice($_SERVER['argv'], 1)));
