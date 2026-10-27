<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
Baander\RegistryTests\TransportFixture::serve($_SERVER['argv'][1]);
