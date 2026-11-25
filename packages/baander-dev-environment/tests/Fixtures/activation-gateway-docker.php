<?php

declare(strict_types=1);
use Baander\DevEnvironment\Tests\Support\GatewayDockerState;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

GatewayDockerState::run($_SERVER['argv']);
