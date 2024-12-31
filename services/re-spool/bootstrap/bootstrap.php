<?php

require __DIR__ . '/../vendor/autoload.php';

$dotenv = \Dotenv\Dotenv::createImmutable(base_path());
$dotenv->load();

$containerBuilder = new \DI\ContainerBuilder;
$containerBuilder->useAttributes(true);
$containerBuilder->useAutowiring(true);
$containerBuilder->addDefinitions( __DIR__ . '/../bootstrap/config.php');

// Optimize container in production
if ($_ENV['APP_ENV'] === 'production' && $_ENV['APP_DEBUG'] === 'false') {
    $containerBuilder->enableCompilation(storage_path('cache'));
    $containerBuilder->writeProxiesToFile(true, storage_path('cache'));
}

$container = $containerBuilder->build();

date_default_timezone_set($container->get('timezone'));

return $container;