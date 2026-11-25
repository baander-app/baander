<?php

declare(strict_types=1);
use Baander\RegistryTests\ObserverServer;
use Baander\RegistryTests\Runtime;

require dirname(__DIR__) . '/bootstrap.php';
global $argv;
Runtime::main(static fn() => ObserverServer::run($argv[1], (int) $argv[2], (int) $argv[3], (int) $argv[4]));
