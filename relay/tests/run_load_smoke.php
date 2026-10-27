<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Baander\RegistryTests\{Runtime,LoadSmoke};

Runtime::main(static function (): void {
    $args = Runtime::arguments(['rqlited', 'server'], ['normal-seconds' => 10, 'burst-seconds' => 10]);
    LoadSmoke::run($args['rqlited'], $args['server'], (int) $args['normal-seconds'], (int) $args['burst-seconds']);
});
