<?php

$container = require __DIR__ . '/bootstrap/bootstrap.php';


use Baander\ReSpool\Kernel;
use Baander\ReSpool\ServerInitializer;

// Boot the kernel
/** @var $kernel \Baander\ReSpool\Kernel */
$kernel = $container->get(Kernel::class);

// Register any shutdown handlers
$kernel->registerShutdownFunction();

// Initialize and start the server
/** @var ServerInitializer $serverInitializer */
$serverInitializer = $container->get(ServerInitializer::class);
$serverInitializer->start();