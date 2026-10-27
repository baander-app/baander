<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
global $argv;
\Baander\RegistryTests\Runtime::main(static fn() => \Baander\RegistryTests\FaultNetworkServer::run($argv[1], json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR)));
