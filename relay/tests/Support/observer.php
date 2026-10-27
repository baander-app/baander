<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
global $argv;
\Baander\RegistryTests\Runtime::main(static fn() => \Baander\RegistryTests\ObserverServer::run($argv[1], (int) $argv[2], (int) $argv[3], (int) $argv[4]));
