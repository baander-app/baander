<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Baander\RegistryTests\{Runtime,StorageContract};

Runtime::main(static function (): void {
    $args = Runtime::arguments(['rqlited', 'server', 'storage-root']);
    StorageContract::run($args['rqlited'], $args['server'], $args['storage-root']);
});
