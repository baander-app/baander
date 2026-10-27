<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
\Baander\RegistryTests\Runtime::main(\Baander\RegistryTests\CleanupContract::cluster(...));
