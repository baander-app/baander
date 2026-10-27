<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Baander\RegistryTests\{BackupCadenceContract, Runtime};

Runtime::main(static function (): void {
    $args = getopt('', ['python:', 'coordinator:']);
    BackupCadenceContract::run($args['python'] ?? 'python3', $args['coordinator'] ?? '/usr/local/bin/registry-backup');
});
