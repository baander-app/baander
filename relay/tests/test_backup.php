<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Baander\RegistryTests\{BackupContract, Runtime};

Runtime::main(static function (): void {
    $args = getopt('', ['python:', 'coordinator:']);
    $checks = BackupContract::run($args['python'] ?? 'python3', $args['coordinator'] ?? dirname(__DIR__) . '/backup/registry_backup.py');
    echo "PASS: backup CLI boundary contract ({$checks} checks)\n";
});
