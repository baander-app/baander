#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

use Baander\RegistryTests\Runtime;
use Baander\RegistryTests\TransportContract;

require __DIR__ . '/bootstrap.php';
Runtime::main(static fn() => TransportContract::main());
