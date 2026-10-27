#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\RegistryTests\BackupContract;
use Baander\RegistryTests\Runtime;

Runtime::main(static function (): void {
    $arguments = Runtime::arguments(['python', 'coordinator']);
    $checks = BackupContract::run($arguments['python'], $arguments['coordinator']);
    echo 'PASS: backup CLI boundary contract (' . $checks . " checks)\n";
});
