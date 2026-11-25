<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);
use Baander\RegistryTests\TransportFixture;

require dirname(__DIR__) . '/bootstrap.php';
TransportFixture::serve($_SERVER['argv'][1]);
