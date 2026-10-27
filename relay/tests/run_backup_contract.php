<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Baander\RegistryTests\{Cluster, EncryptedBackupContract, RestoreContract, Runtime};

Runtime::main(static function (): void {
    $args = getopt('', ['rqlited:', 'server:', 'python:', 'coordinator:', 'diagnostics:']);
    Runtime::requireThat(isset($args['rqlited'], $args['server']), 'Expected --rqlited and --server.');
    RestoreContract::runRestoreScenario(
        $args['rqlited'],
        $args['server'],
        snapshotProvider: static fn(Cluster $source, int $leader, string $root): string => EncryptedBackupContract::snapshot(
            $source,
            $leader,
            $root,
            $args['python'] ?? 'python3',
            $args['coordinator'] ?? '/usr/local/bin/registry-backup',
        ),
        diagnostics: $args['diagnostics'] ?? null,
    );
});
