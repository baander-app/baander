<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Baander\RegistryTests;

final class HttpProxy
{
    /**
     * @param callable(array<string,mixed>&):void|null $change
     * @return array<string,mixed>
     */
    public static function state(string $path, ?callable $change = null): array
    {
        $file = fopen($path, 'c+');
        if ($file === false || !flock($file, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock disposable proxy state.');
        }
        try {
            $state = json_decode(stream_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            if ($change !== null) {
                $change($state);
                rewind($file);
                ftruncate($file, 0);
                fwrite($file, json_encode($state, JSON_THROW_ON_ERROR));
                fflush($file);
            }
            return $state;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
    public static function serve(string $path): void
    {
        $config = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        $server = new \Swoole\Http\Server('127.0.0.1', $config['port'], SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
        $server->set([
            'worker_num' => 1,
            'enable_coroutine' => true,
            'ssl_cert_file' => $config['certificate'],
            'ssl_key_file' => $config['key'],
            'ssl_client_cert_file' => $config['ca'],
            'ssl_verify_peer' => true,
            'ssl_allow_self_signed' => false,
            'log_file' => $config['log'],
            'log_level' => SWOOLE_LOG_ERROR,
        ]);
        $server->on(
            'request',
            static function ($request, $response) use ($config): void {
                $body = $request->rawContent();
                $drop = false;
                $state = self::state(
                    $config['state'],
                    static function (array &$state) use ($body, &$drop): void {
                        if (!str_contains($body, 'INSERT INTO registries')) {
                            return;
                        }
                        $statements = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                        if (($statements[1][1]['public_id'] ?? null) === 'lost-response-committed') {
                            ++$state['writes'];
                            $drop = $state['armed'];
                            $state['armed'] = false;
                        }
                        if ($state['delay'] > 0) {
                            ++$state['arrivals'];
                        }
                    },
                );
                if (str_contains($body, 'INSERT INTO registries') && $state['delay'] > 0) {
                    usleep((int) ($state['delay'] * 1000000));
                }
                try {
                    $target = $request->server['request_uri'];
                    if (isset($request->server['query_string']) && $request->server['query_string'] !== '') {
                        $target .= '?' . $request->server['query_string'];
                    }
                    [$status, $payload] = Http::request(
                        'rqlite.baander.app',
                        '127.0.0.1',
                        $config['databasePort'],
                        [
                            'cafile' => $config['ca'],
                            'local_cert' => $config['clientCertificate'],
                            'local_pk' => $config['clientKey'],
                        ],
                        'POST',
                        $target,
                        $body,
                        ['Content-Type' => 'application/json', 'Authorization' => $request->header['authorization'] ?? ''],
                    );
                    if ($drop) {
                        $error = null;
                        try {
                            $committed = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
                            Runtime::requireThat($status === 200 && !isset($committed['error']), 'Lost response database status');
                            Runtime::requireThat(count($committed['results']) === 3, 'Lost response result count');
                            foreach ($committed['results'] as $item) {
                                Runtime::requireThat(!isset($item['error']), 'Lost response SQL error');
                            }
                            Runtime::requireThat($committed['results'][1]['rows_affected'] === 1, 'Lost response write count');
                            Runtime::requireThat($committed['results'][2]['rows'][0]['revision'] === 1, 'Lost response revision');
                        } catch (\Throwable $failure) {
                            $error = $failure->getMessage();
                        }
                        self::state(
                            $config['state'],
                            static function (array &$state) use ($error): void {
                                $state['completed'] = true;
                                $state['error'] = $error;
                            },
                        );
                        $response->close();
                        return;
                    }
                    if ($state['malformedReadiness'] && str_contains($body, 'FROM registries WHERE 0')) {
                        $corrupted = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
                        $corrupted['results'][1] = ['types' => ['public_id' => 'text']];
                        $payload = json_encode($corrupted, JSON_THROW_ON_ERROR);
                    }
                    $response->status($status);
                    $response->end($payload);
                } catch (\Throwable $failure) {
                    if ($drop) {
                        self::state(
                            $config['state'],
                            static function (array &$state) use ($failure): void {
                                $state['completed'] = true;
                                $state['error'] = $failure->getMessage();
                            },
                        );
                    }
                    $response->close();
                }
            },
        );
        $server->start();
    }
}
