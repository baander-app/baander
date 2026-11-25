<?php

declare(strict_types=1);
use Baander\RegistryTests\CleanupContract;
use Baander\RegistryTests\Runtime;

require __DIR__ . '/bootstrap.php';
Runtime::main(CleanupContract::storage(...));
