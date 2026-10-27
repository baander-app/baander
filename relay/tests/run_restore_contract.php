<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Baander\RegistryTests\{Runtime,RestoreContract};

Runtime::main(static function (): void {
    $args = Runtime::arguments(['rqlited', 'server'], ['nodes' => 3, 'diagnostics' => null]);
    RestoreContract::runRestoreScenario($args['rqlited'], $args['server'], (int) $args['nodes'], diagnostics: $args['diagnostics']);
});
