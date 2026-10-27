#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
Baander\RegistryTests\Runtime::main(static fn() => Baander\RegistryTests\RqliteContract::main());
