<?php

declare(strict_types=1);
use Baander\RegistryTests\FaultNetworkServer;
use Baander\RegistryTests\Runtime;

require dirname(__DIR__) . '/bootstrap.php';
global $argv;
Runtime::main(static fn() => FaultNetworkServer::run($argv[1], json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR)));
