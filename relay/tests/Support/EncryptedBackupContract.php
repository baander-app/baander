<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Baander\RegistryTests;

final class EncryptedBackupContract
{
    /**
     * @param list<string> $argv
     * @param array<string,string>|null $env
     */
    private static function command(array $argv, ?string $input = null, ?array $env = null): string
    {
        $result = Runtime::run($argv, $input, 60, $env);
        Runtime::requireThat($result['code'] === 0, 'Backup fixture command failed: ' . implode(' ', $argv) . "\n" . substr($result['stderr'], -4096));
        return $result['stdout'];
    }

    public static function snapshot(Cluster $source, int $leader, string $root, string $python, string $coordinator): string
    {
        Runtime::requireThat(class_exists(\SQLite3::class), 'Encrypted recovery requires the SQLite3 extension.');
        $fixture = $root . '/encrypted-backup';
        mkdir($fixture, 0700);
        foreach (['objects', 'objects/registry-backups', 'spool'] as $directory) {
            mkdir($fixture . '/' . $directory, 0700);
        }
        $identity = self::command(['age-keygen']);
        file_put_contents($fixture . '/recipients.txt', self::command(['age-keygen', '-y'], $identity));
        $tlsKey = $fixture . '/s3.key';
        $tlsCert = $fixture . '/s3.crt';
        self::command([
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', $tlsKey,
            '-out', $tlsCert,
            '-days', '1',
            '-subj', '/CN=backup-fixture.baander.app',
            '-addext', 'subjectAltName=IP:127.0.0.1',
        ]);
        $port = Runtime::port();
        $environment = [...getenv(), 'RCLONE_CA_CERT' => $tlsCert, 'NO_PROXY' => '*', 'no_proxy' => '*'];
        $rclone = $fixture . '/rclone.conf';
        file_put_contents(
            $rclone,
            "[backup]\ntype = s3\nprovider = Other\nenv_auth = false\n"
                . "access_key_id = disposable-access\nsecret_access_key = disposable-secret\n"
                . "region = us-east-1\nendpoint = https://127.0.0.1:{$port}\nuse_multipart_uploads = false\n",
        );
        chmod($rclone, 0600);
        $curl = $fixture . '/curl.conf';
        file_put_contents(
            $curl,
            implode("\n", [
                'user = "registry:disposable-cluster-secret"',
                'cacert = "' . $source->directory . '/ca.crt"',
                'cert = "' . $source->clientCert . '"',
                'key = "' . $source->clientKey . '"',
                'resolve = "node-' . $leader . '.rqlite.baander.app:' . $source->httpPorts[$leader] . ':127.0.0.1"',
                'noproxy = "*"',
                '',
            ]),
        );
        chmod($curl, 0600);
        $config = [
            'rqlite_url' => 'https://node-' . $leader . '.rqlite.baander.app:' . $source->httpPorts[$leader],
            'curl_config' => $curl,
            'recipients_file' => $fixture . '/recipients.txt',
            'rclone_config' => $rclone,
            'destination' => 'backup:registry-backups/registry',
            'spool_dir' => $fixture . '/spool',
            'status_file' => $fixture . '/status.json',
            'attempt_timeout_seconds' => 45,
            'max_ciphertext_bytes' => 1048576,
        ];
        $path = $fixture . '/backup.json';
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
        $service = new Process(
            [
                'rclone', 'serve', 's3', $fixture . '/objects',
                '--addr', '127.0.0.1:' . $port,
                '--auth-key', 'disposable-access,disposable-secret',
                '--cert', $tlsCert,
                '--key', $tlsKey,
            ],
            $fixture . '/s3.log',
            $environment,
        );
        $successes = $downloaded = [];
        try {
            $deadline = microtime(true) + 10;
            while (true) {
                Runtime::requireThat($service->poll() === null, 'TLS S3 fixture exited: ' . file_get_contents($fixture . '/s3.log'));
                $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, timeout: 0.2);
                if ($socket !== false) {
                    fclose($socket);
                    break;
                }
                Runtime::requireThat(microtime(true) < $deadline, 'TLS S3 fixture did not start.');
                usleep(50000);
            }
            for ($index = 0; $index < 2; ++$index) {
                self::command([$python, $coordinator, 'once', '--config', $path], env: $environment);
                $status = file_get_contents($fixture . '/status.json')
                    |> (static fn(string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR));
                Runtime::requireThat($status['latest_attempt']['outcome'] === 'success', 'Encrypted capture failed.');
                self::command([$python, $coordinator, 'health', '--config', $path], env: $environment);
                $successes[] = $status['last_success'];
                Runtime::requireThat(count(scandir($fixture . '/spool')) === 2, 'Completed capture retained spool artifacts.');
            }
            Runtime::requireThat($successes[0]['object_key'] !== $successes[1]['object_key'], 'Historical objects collided.');
            $listing = self::command(
                ['rclone', '--config', $rclone, 'lsjson', 'backup:registry-backups/registry'],
                env: $environment,
            ) |> (static fn(string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR));
            $names = array_column($listing, 'Name');
            sort($names);
            $expected = array_map(static fn(array $success): string => basename($success['object_key']), $successes);
            sort($expected);
            Runtime::requireThat($names === $expected, 'TLS storage history differs from published status.');
            foreach ($successes as $index => $success) {
                $target = $fixture . '/download-' . $index . '.sqlite.age';
                self::command(['rclone', '--config', $rclone, 'copyto', 'backup:' . $success['object_key'], $target], env: $environment);
                $cipher = file_get_contents($target);
                Runtime::requireThat(
                    str_starts_with($cipher, "age-encryption.org/v1\n") && !str_contains($cipher, "SQLite format 3\0"),
                    'Object is not encrypted age ciphertext.',
                );
                Runtime::requireThat(hash('sha256', $cipher) === $success['sha256'], 'Ciphertext digest differs from published status.');
                $downloaded[] = $target;
            }
        } finally {
            $service->close(false);
        }
        $recovery = $fixture . '/recovery';
        mkdir($recovery, 0700);
        $private = $recovery . '/identity.txt';
        file_put_contents($private, $identity);
        chmod($private, 0600);
        $wrong = $recovery . '/wrong-identity.txt';
        file_put_contents($wrong, self::command(['age-keygen']));
        chmod($wrong, 0600);
        $damaged = file_get_contents($downloaded[0]);
        $damaged[strlen($damaged) - 1] = chr(ord($damaged[strlen($damaged) - 1]) ^ 1);
        $modified = $recovery . '/modified.sqlite.age';
        file_put_contents($modified, $damaged);
        $rejectedCases = [
            ['wrong-identity', $wrong, $downloaded[0]],
            ['modified', $private, $modified],
        ];
        foreach ($rejectedCases as [$label, $key, $cipher]) {
            $rejected = $recovery . '/' . $label . '.sqlite';
            $result = Runtime::run(['age', '--decrypt', '--identity', $key, '--output', $rejected, $cipher], timeout: 10);
            Runtime::requireThat($result['code'] !== 0, 'Rejected ciphertext was accepted: ' . $label);
            if (file_exists($rejected)) {
                unlink($rejected);
            }
        }
        $recovered = [];
        foreach ($downloaded as $index => $cipher) {
            $target = $recovery . '/verified-' . $index . '.sqlite';
            self::command(['age', '--decrypt', '--identity', $private, '--output', $target, $cipher]);
            $database = new \SQLite3($target, SQLITE3_OPEN_READONLY);
            try {
                Runtime::requireThat($database->querySingle('PRAGMA integrity_check') === 'ok', 'Decrypted snapshot failed integrity check.');
                $rows = [];
                foreach ([RestoreContract::REGISTRY_QUERY, RestoreContract::MIGRATION_QUERY] as $query) {
                    $result = $database->query($query);
                    $values = [];
                    Runtime::requireThat($result !== false, 'Recovered snapshot query failed.');
                    while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
                        $values[] = $row;
                    }
                    $rows[] = $values;
                    $result->finalize();
                }
                $recovered[] = $rows;
            } finally {
                $database->close();
            }
        }
        Runtime::requireThat($recovered[0] === $recovered[1], 'Historical captures changed source rows.');
        echo "PASS: encrypted TLS S3 history, independent hashes, offline recovery and wrong identity/tamper rejection\n";
        return file_get_contents($recovery . '/verified-0.sqlite');
    }
}
