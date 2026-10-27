<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Baander\RegistryTests\{Runtime,ClusterContract};

Runtime::main(static function (): void {
    $args = Runtime::arguments(['rqlited', 'server', 'nodes'], ['diagnostics' => null]);
    ClusterContract::run($args['rqlited'], $args['server'], (int) $args['nodes'], $args['diagnostics']);
});
