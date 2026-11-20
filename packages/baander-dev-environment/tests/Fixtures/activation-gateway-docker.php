<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

Baander\DevEnvironment\Tests\Support\GatewayDockerState::run($_SERVER['argv']);
