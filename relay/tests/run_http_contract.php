#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Baander\RegistryTests\HttpContract;
use Baander\RegistryTests\HttpProxy;
use Baander\RegistryTests\Runtime;

if (($argv[1] ?? '') === '--proxy-file') {
    HttpProxy::serve($argv[2]);
    exit(0);
}
if (($argv[1] ?? '') === '--request-file') {
    $config = json_decode(file_get_contents($argv[2]), true, 64, JSON_THROW_ON_ERROR);
    try {
        $result = \Baander\RegistryTests\Http::request(
            'api.registry.baander.app',
            '127.0.0.1',
            $config['port'],
            $config['tls'],
            'POST',
            '/api/servers/register',
            $config['body'],
            $config['headers'],
        );
        $outcome = ['result' => $result];
    } catch (\Throwable $error) {
        $outcome = ['error' => $error->getMessage()];
    }
    file_put_contents($config['path'] . '.pending', json_encode($outcome, JSON_THROW_ON_ERROR));
    rename($config['path'] . '.pending', $config['path']);
    exit(0);
}
Runtime::main(static fn() => HttpContract::main());
