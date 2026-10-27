#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\RegistryTests\Runtime;

Runtime::main(static function (): void {
    $arguments = Runtime::arguments(['python', 'coordinator', 'rqlited', 'server']);
    $deadline = Runtime::now() + 295;
    foreach (['run_backup_fault_contract', 'run_backup_contract', 'run_restore_contract'] as $contract) {
        $options = $contract === 'run_restore_contract'
            ? ['--rqlited', $arguments['rqlited'], '--server', $arguments['server']]
            : [
                '--python',
                $arguments['python'],
                '--coordinator',
                $arguments['coordinator'],
                ...($contract === 'run_backup_contract' ? ['--rqlited', $arguments['rqlited'], '--server', $arguments['server']] : []),
            ];
        $remaining = $deadline - Runtime::now();
        Runtime::requireThat($remaining > 0, 'Backup qualification exceeded its 300-second bound.');
        echo Runtime::checked([PHP_BINARY, __DIR__ . '/' . $contract . '.php', ...$options], timeout: $remaining);
    }
});
