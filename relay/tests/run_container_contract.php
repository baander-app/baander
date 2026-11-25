#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

use Baander\RegistryTests\ContainerContract;
use Baander\RegistryTests\Runtime;

require __DIR__ . '/bootstrap.php';
Runtime::main(ContainerContract::main(...));
