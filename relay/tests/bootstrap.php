<?php

declare(strict_types=1);

if (version_compare(PHP_VERSION, '8.5.0', '<') || !extension_loaded('swoole')) {
    fwrite(STDERR, "Registry harnesses require PHP 8.5+ with Swoole.\n");
    exit(1);
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Registry test dependencies are missing; run composer install --working-dir=relay.\n");
    exit(1);
}
require_once $autoload;
