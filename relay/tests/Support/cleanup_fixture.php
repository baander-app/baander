#!/usr/bin/env php
<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
global $argv;
\Baander\RegistryTests\CleanupFixture::run($argv[1] === '--config' ? 'api' : $argv[1], $argv[2]);
