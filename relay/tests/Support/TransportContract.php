<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Baander\RegistryTests;

final class TransportContract
{
    public static function main(): void
    {
        $options = getopt('', ['fixture:']);
        Runtime::requireThat(isset($options['fixture']), '--fixture is required');
        $directory = Runtime::temporaryDirectory('baander-registry-tls-');
        $processes = [];
        $stops = [];
        try {
            Tls::authority($directory);
            [$serverKey, $serverCertificate] = Tls::certificate($directory, 'server', 'rqlite.baander.app');
            [$clientKey, $clientCertificate] = Tls::certificate($directory, 'client');
            file_put_contents($directory . '/password', "disposable-test-secret\n");
            $events = $directory . '/events';
            file_put_contents($events, '');
            $start = static function (string $name, string $key, string $certificate, bool $legacy = false, ?string $body = null) use ($directory, $events, &$processes, &$stops): array {
                $port = Runtime::port();
                $state = $directory . '/' . $name . '-state.json';
                file_put_contents(
                    $state,
                    json_encode([
                        'status' => 200,
                        'delay' => 0,
                        'delayOnce' => false,
                        'body' => $body ?? '{"results":[{"values":[[1]]}]}',
                    ]),
                );
                $ready = $directory . '/' . $name . '-ready';
                $stops[] = $ready . '.stop';
                $configuration = $directory . '/' . $name . '.json';
                file_put_contents(
                    $configuration,
                    json_encode([
                        'port' => $port,
                        'state' => $state,
                        'ready' => $ready,
                        'events' => $events,
                        'key' => $key,
                        'certificate' => $certificate,
                        'ca' => $directory . '/ca.crt',
                        'legacy' => $legacy,
                    ]),
                );
                $process = new Process([PHP_BINARY, __DIR__ . '/transport_fixture.php', $configuration], $directory . '/' . $name . '.log');
                $processes[] = $process;
                $until = microtime(true) + 5;
                while (!is_file($ready)) {
                    Runtime::requireThat($process->poll() === null && microtime(true) < $until, 'TLS fixture failed to start');
                    usleep(10000);
                }
                return [$port, $state];
            };
            [$port, $state] = $start('main', $serverKey, $serverCertificate);
            $config = [
                'url' => 'https://rqlite.baander.app:' . $port,
                'ca' => $directory . '/ca.crt',
                'certificate' => $clientCertificate,
                'key' => $clientKey,
                'passwordFile' => $directory . '/password',
                'deadlineMs' => 250,
            ];
            $readEvents = static fn(): array => array_map(
                static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($events, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
            $requests = static fn(): array => $readEvents()
                |> (static fn(array $events): array => array_filter($events, static fn(array $event): bool => $event['type'] === 'request'))
                |> array_values(...);
            $names = static fn(): array => $readEvents()
                |> (static fn(array $events): array => array_filter($events, static fn(array $event): bool => $event['type'] === 'handshake'))
                |> array_values(...);
            $change = static function (array $changes) use ($state): void {
                $file = fopen($state, 'r+');
                flock($file, LOCK_EX);
                $value = json_decode(stream_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                rewind($file);
                ftruncate($file, 0);
                fwrite($file, json_encode([...$value, ...$changes]));
                fflush($file);
                flock($file, LOCK_UN);
                fclose($file);
            };
            $run = static function (array $expected, int $count = 1, bool $ordered = false) use (&$config, $directory, $options): void {
                file_put_contents($directory . '/config.json', json_encode($config));
                $result = Runtime::run([$options['fixture'], $directory . '/config.json', (string) $count], null, 5);
                Runtime::requireThat($result['code'] === 0, 'Transport fixture failed: ' . $result['stdout'] . $result['stderr']);
                $diagnostic = $result['stdout'] . $result['stderr'];
                Runtime::requireThat(
                    !str_contains($diagnostic, 'disposable-test-secret') && !str_contains($diagnostic, base64_encode('registry:disposable-test-secret')),
                    'Transport leaked credentials',
                );
                $actual = explode("\n", trim($result['stdout']));
                if (!$ordered) {
                    sort($actual);
                    sort($expected);
                }
                Runtime::requireThat($actual === $expected, 'Unexpected transport results: ' . $diagnostic);
            };
            $run(['200']);
            Runtime::requireThat(array_column($names(), 'name') === ['rqlite.baander.app'], 'TLS SNI mismatch');
            $first = $requests()[0];
            Runtime::requireThat(
                $first['target'] === '/db/query?level=linearizable&associative' && $first['authorization'] === 'Basic ' . base64_encode('registry:disposable-test-secret') && $first['peer'],
                'Mutual TLS/authenticated target mismatch',
            );
            $change(['delay' => 0.6]);
            $begin = microtime(true);
            $run(['503', '503'], 2);
            Runtime::requireThat(microtime(true) - $begin < 1.5, 'Pool saturation/deadline exceeded bound');
            $change(['delay' => 0.6, 'delayOnce' => true]);
            $run(['503', '200'], -2);
            $before = count($names());
            $run(['200', '200'], -2);
            Runtime::requireThat(count($names()) === $before + 1, 'Sequential requests did not reuse TLS connection');
            $healthyBody = json_encode(['results' => [[
                'types' => [
                    'public_id' => 'text',
                    'url' => 'text',
                    'name' => 'text',
                    'version' => 'text',
                    'updated_ms' => 'integer',
                    'last_seen_ms' => 'integer',
                    'revision' => 'integer',
                ],
                'rows' => [[
                    'public_id' => 'rotation-fixture',
                    'url' => 'https://rotation.baander.app',
                    'name' => 'Rotation fixture',
                    'version' => '1.0.0',
                    'updated_ms' => 2000,
                    'last_seen_ms' => 2000,
                    'revision' => 1,
                ]],
            ]]]);
            [$healthyPort] = $start('healthy', $serverKey, $serverCertificate, false, $healthyBody);
            $config['additionalUrls'] = ['https://rqlite.baander.app:' . $healthyPort];
            $config['rotateOnError'] = true;
            $change(['body' => '{"results":[],"error":"leadership lost while committing log"}']);
            $before = count($requests());
            $run(['503', '200'], -2, true);
            $arrivals = array_slice($requests(), $before);
            Runtime::requireThat(
                array_column($arrivals, 'port') === [$port, $healthyPort]
                    && array_column($arrivals, 'target') === [
                        '/db/request?transaction&level=linearizable&associative',
                        '/db/query?level=linearizable&associative',
                    ],
                'Uncertain write replayed or lookup did not rotate',
            );
            unset($config['additionalUrls'], $config['rotateOnError']);
            $change(['status' => 301]);
            $run(['503']);
            $change(['status' => 200, 'body' => 'not-json']);
            $run(['503']);
            $change(['body' => '{"results":[]}']);
            $config['url'] = 'https://wrong.registry.baander.app:' . $port;
            $before = count($requests());
            $run(['503']);
            Runtime::requireThat(count($requests()) === $before, 'Wrong TLS hostname reached authenticated HTTP');
            $config['url'] = 'https://rqlite.baander.app:' . $port;
            $change(['body' => '{"results":[{"values":[[1]]}]}']);
            $rejected = static function (string $label, array $changes) use (&$config, $requests, $run): void {
                $previous = $config;
                $before = count($requests());
                $config = [...$config, ...$changes];
                try {
                    $run(['503', '503'], -2, true);
                    Runtime::requireThat(count($requests()) === $before, $label . ' reached authenticated HTTP');
                } finally {
                    $config = $previous;
                }
                $run(['200']);
                Runtime::requireThat(count($requests()) === $before + 1, $label . ' prevented healthy recovery');
            };
            mkdir($directory . '/unrelated');
            Tls::authority($directory . '/unrelated');
            $rejected('Untrusted server chain', ['ca' => $directory . '/unrelated/ca.crt']);
            [$rogueKey, $rogueCert] = Tls::certificate($directory . '/unrelated', 'untrusted-client');
            $rejected('Untrusted client chain', ['certificate' => $rogueCert, 'key' => $rogueKey]);
            [$expiredKey, $expiredCert] = Tls::certificate($directory, 'expired-server', 'rqlite.baander.app', true);
            $check = Runtime::run(['openssl', 'x509', '-in', $expiredCert, '-checkend', '0', '-noout']);
            Runtime::requireThat($check['code'] === 1, 'Expired certificate fixture is still valid');
            [$expiredPort] = $start('expired', $expiredKey, $expiredCert);
            $rejected('Expired server certificate', ['url' => 'https://rqlite.baander.app:' . $expiredPort]);
            [$legacyPort] = $start('legacy', $serverKey, $serverCertificate, true);
            $context = stream_context_create(['ssl' => [
                'cafile' => $directory . '/ca.crt',
                'local_cert' => $clientCertificate,
                'local_pk' => $clientKey,
                'peer_name' => 'rqlite.baander.app',
                'verify_peer' => true,
                'verify_peer_name' => true,
                'ciphers' => 'DEFAULT:@SECLEVEL=0',
                'security_level' => 0,
            ]]);
            $control = stream_socket_client('tcp://127.0.0.1:' . $legacyPort, $error, $message, 2, STREAM_CLIENT_CONNECT, $context);
            Runtime::requireThat($control !== false, 'Legacy TLS control failed to connect');
            try {
                stream_set_timeout($control, 2);
                Runtime::requireThat(
                    stream_socket_enable_crypto($control, true, STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT) === true,
                    'Legacy TLS control handshake failed',
                );
                Runtime::requireThat(
                    stream_get_meta_data($control)['crypto']['protocol'] === 'TLSv1.1',
                    'Legacy TLS control protocol mismatch',
                );
            } finally {
                fclose($control);
            }
            $rejected('TLS 1.1 protocol', ['url' => 'https://rqlite.baander.app:' . $legacyPort]);
            echo "PASS: native TLS authentication/SNI, saturation/deadline, same-pool reconnect/keepalive, "
                . "observed-error rotation without replay, redirect, malformed response, hostname verification, "
                . "untrusted server/client chains, expired server certificate, TLS 1.1 rejection with positive control, "
                . "credential redaction and healthy recovery.\n";
        } finally {
            foreach ($stops as $stop) {
                file_put_contents($stop, 'stop');
            }
            foreach (array_reverse($processes) as $process) {
                $process->wait(3);
                $process->close();
            }
            Runtime::removeTree($directory);
        }
    }
}
