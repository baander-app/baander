<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Baander\RegistryTests;

final class RqliteContract
{
    public static function main(): void
    {
        $options = getopt('', ['rqlited:', 'fixture:']);
        Runtime::requireThat(isset($options['rqlited'], $options['fixture']), '--rqlited and --fixture are required');
        $result = Runtime::run([$options['fixture']]);
        Runtime::requireThat($result['code'] === 0, 'SQL fixture failed');
        $operations = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $directory = Runtime::temporaryDirectory('baander-registry-contract-');
        $httpPort = Runtime::port();
        $raftPort = Runtime::port();
        $process = null;
        try {
            $process = new Process(
                [
                    $options['rqlited'],
                    '-node-id',
                    'fixture',
                    '-http-addr',
                    '127.0.0.1:' . $httpPort,
                    '-raft-addr',
                    '127.0.0.1:' . $raftPort,
                    $directory . '/data',
                ],
                $directory . '/rqlite.log',
            );
            $deadline = microtime(true) + 15;
            while (true) {
                Runtime::requireThat($process->poll() === null, 'Disposable rqlite exited before readiness');
                try {
                    if (Http::request('127.0.0.1', '127.0.0.1', $httpPort, [], 'GET', '/readyz', timeout: 1)[0] === 200) {
                        break;
                    }
                } catch (\RuntimeException) {
                }
                Runtime::requireThat(microtime(true) < $deadline, 'Disposable rqlite did not become ready');
                usleep(50000);
            }
            foreach ($operations as $operation) {
                [$status, $body] = Http::request(
                    '127.0.0.1',
                    '127.0.0.1',
                    $httpPort,
                    [],
                    'POST',
                    $operation['target'],
                    json_encode($operation['statements']),
                    ['Content-Type' => 'application/json'],
                    5,
                );
                Runtime::requireThat($status === 200, 'Native rqlite HTTP failure');
                $response = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                Runtime::requireThat(
                    !isset($response['error']) && count($response['results']) === count($operation['statements']),
                    $operation['name'],
                );
                foreach ($response['results'] as $row) {
                    Runtime::requireThat(!isset($row['error']), $operation['name']);
                }
                $last = $response['results'][array_key_last($response['results'])];
                if ($operation['kind'] !== 'maintenance') {
                    Runtime::requireThat(
                        isset($last['types']) && is_array($last['types']) && !array_is_list($last['types']),
                        'Missing authoritative query column types',
                    );
                }
                $result = Runtime::run([$options['fixture'], 'decode'], json_encode([...$operation, 'response' => $response]));
                Runtime::requireThat($result['code'] === 0, 'Core decoder process failed');
                $decoded = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
                $expected = ['conflict' => 403, 'offline' => 404, 'schema-invalid' => 503][$operation['kind']] ?? 200;
                Runtime::requireThat($decoded['status'] === $expected, 'Core decoder rejected native result: ' . $operation['name']);
                if (in_array($operation['kind'], ['register', 'lookup'], true)) {
                    Runtime::requireThat($decoded['body']['data']['revision'] === $operation['revision'], $operation['name']);
                }
                if ($operation['kind'] === 'offline') {
                    Runtime::requireThat(!array_key_exists('body', $decoded), 'Offline response must not return stale server metadata');
                }
                if ($operation['kind'] === 'conflict') {
                    Runtime::requireThat(empty($last['rows']), $operation['name']);
                } elseif (in_array($operation['kind'], ['register', 'lookup'], true)) {
                    $rows = $last['rows'];
                    Runtime::requireThat(count($rows) === 1 && $rows[0]['revision'] === $operation['revision'], $operation['name']);
                    if ($operation['name'] === 'owned metadata update') {
                        Runtime::requireThat($rows[0]['last_seen_ms'] === 2000, 'Clock reversal moved last seen backwards');
                        Runtime::requireThat($rows[0]['updated_ms'] === 2000, 'Clock reversal moved updated timestamp backwards');
                    }
                    if ($operation['kind'] === 'lookup') {
                        Runtime::requireThat(!array_key_exists('credential_digest', $rows[0]), 'Lookup returned credential digest');
                    }
                }
                echo 'PASS: ' . $operation['name'] . "\n";
            }
        } finally {
            $process?->close();
            Runtime::removeTree($directory);
        }
    }
}
