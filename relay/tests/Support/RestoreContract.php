<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

use PDO;

final class RestoreContract
{
    public const string SCHEMA_CHECKSUM = '7dd3d552cbd6e3053846485d17b6dddf6cfbae2ef41136a1fa6fbaf06a38fcfd';
    public const string REGISTRY_QUERY = 'SELECT * FROM registries ORDER BY public_id';
    public const string MIGRATION_QUERY = 'SELECT version, checksum FROM schema_migrations ORDER BY version';
    public const int MAX_BACKUP_BYTES = 1048576;
    /**
     * @return array{int,string,?string} */
    public static function binaryRequest(Cluster $cluster, int $leader, string $method, string $target, ?string $payload = null): array
    {
        [$status, $body, $headers] = Http::request(
            "node-{$leader}.rqlite.baander.app",
            '127.0.0.1',
            $cluster->httpPorts[$leader],
            $cluster->tls,
            $method,
            $target,
            $payload,
            [
                'Authorization' => 'Basic ' . base64_encode('registry:disposable-cluster-secret'),
                'Content-Type' => 'application/octet-stream',
            ],
            10,
            self::MAX_BACKUP_BYTES,
        );
        return [$status, $body, $headers['content-type'] ?? null];
    }
    /**
     * @return list<array<string,mixed>> */
    public static function queryRows(Cluster $cluster, int $index, string $query, string $level = 'linearizable'): array
    {
        [$status, $body] = $cluster->request($index, "/db/query?level={$level}&associative", [$query]);
        Runtime::requireThat(
            $status === 200 && !isset($body['error']) && !isset($body['results'][0]['error']),
            'Restore query failed: ' . json_encode($body),
        );
        return $body['results'][0]['rows'] ?? [];
    }
    /**
     * @param list<array<string,mixed>> $expected
     * @param list<array<string,mixed>> $migrations */
    public static function waitRows(Cluster $cluster, array $expected, array $migrations): void
    {
        $until = Runtime::now() + 30;
        do {
            try {
                $converged = true;
                for ($index = 0; $index < $cluster->count; ++$index) {
                    $converged = $converged
                        && self::queryRows($cluster, $index, self::REGISTRY_QUERY, 'none') == $expected
                        && self::queryRows($cluster, $index, self::MIGRATION_QUERY, 'none') == $migrations;
                }
                if ($converged) {
                    return;
                }
            } catch (\Throwable) {
            }
            usleep(50000);
        } while (Runtime::now() < $until);
        throw new \RuntimeException('Restored rows did not converge on every voter within 30 seconds.');
    }
    /**
     * @return array<string,mixed> */
    private static function lookup(Api $api, string $identifier): array
    {
        [$status, $body] = $api->request('GET', '/api/servers/' . $identifier);
        Runtime::requireThat(
            $status === 200 && !array_key_exists('credential_digest', $body['data'])
                && !array_key_exists('apiKey', $body['data']),
            'Restore public lookup failed.',
        );
        return $body['data'];
    }
    /**
     * @param callable(Cluster,int,string):string|null $snapshotProvider */
    public static function runRestoreScenario(string $rqlited, string $server, int $nodes = 3, ?callable $snapshotProvider = null, ?string $diagnostics = null): void
    {
        Runtime::requireThat(extension_loaded('pdo_sqlite'), 'Restore qualification requires PDO SQLite.');
        Runtime::requireThat(in_array($nodes, [3, 5], true), 'Node count must be 3 or 5.');
        $root = Runtime::temporaryDirectory('baander-registry-restore-');
        $sourceDirectory = $root . '/source';
        $restoredDirectory = $root . '/restored';
        mkdir($sourceDirectory, 0700);
        mkdir($restoredDirectory, 0700);
        $source = $restored = $sourceApi = $restoredApi = null;
        $scenarioFailure = null;
        try {
            $source = new Cluster($sourceDirectory, $rqlited, $nodes);
            $leader = $source->start();
            $sourceApi = new Api(
                $source,
                $server,
                'source-api',
                array_map(static fn(int $index): array => [$index, $source->httpPorts[$index]], range(0, $nodes - 1)),
            );
            $values = [];
            for ($number = 0; $number < 3; ++$number) {
                $value = ClusterContract::registration("backup-owner-{$number}");
                [$status, $body] = $sourceApi->request('POST', '/api/servers/register', $value);
                Runtime::requireThat($status === 200 && $body['data']['revision'] === 1);
                $values[] = $value;
            }
            $values[0]['name'] = 'Updated before backup';
            $values[0]['version'] = '2.0.0';
            [$status, $body] = $sourceApi->request('POST', '/api/servers/register', $values[0]);
            Runtime::requireThat($status === 200 && $body['data']['revision'] === 2);
            $public = [];
            foreach ($values as $value) {
                $public[$value['publicId']] = self::lookup($sourceApi, $value['publicId']);
            }
            $rows = self::queryRows($source, $leader, self::REGISTRY_QUERY);
            $migrations = [['version' => 1, 'checksum' => self::SCHEMA_CHECKSUM]];
            Runtime::requireThat(self::queryRows($source, $leader, self::MIGRATION_QUERY) == $migrations);
            foreach ($rows as $index => $row) {
                $value = $values[$index];
                Runtime::requireThat($row['public_id'] === $value['publicId']
                    && $row['credential_digest'] === hash('sha256', $value['apiKey'])
                    && $row['revision'] === $public[$value['publicId']]['revision']);
            }
            if ($snapshotProvider === null) {
                [$status, $backup, $contentType] = self::binaryRequest($source, $leader, 'GET', '/db/backup?fmt=binary');
                Runtime::requireThat($status === 200 && $contentType === 'application/octet-stream');
            } else {
                $backup = $snapshotProvider($source, $leader, $root);
            }
            Runtime::requireThat(
                strlen($backup) <= self::MAX_BACKUP_BYTES && str_starts_with($backup, "SQLite format 3\x00"),
                'Backup is not a bounded SQLite database.',
            );
            $path = $root . '/backup.sqlite';
            file_put_contents($path, $backup);
            $database = new PDO('sqlite:file:' . $path . '?mode=ro');
            Runtime::requireThat($database->query('PRAGMA integrity_check')->fetchColumn() === 'ok');
            Runtime::requireThat($database->query(self::REGISTRY_QUERY)->fetchAll(PDO::FETCH_ASSOC) == $rows);
            Runtime::requireThat($database->query(self::MIGRATION_QUERY)->fetchAll(PDO::FETCH_ASSOC) == $migrations);
            $database = null;
            [$status, $body] = $sourceApi->request('POST', '/api/servers/register', $values[0]);
            Runtime::requireThat($status === 200 && $body['data']['revision'] === 3);
            Runtime::requireThat($sourceApi->request('POST', '/api/servers/register', ClusterContract::registration('after-backup-sentinel'))[0] === 200);
            Runtime::requireThat($sourceApi->request('GET', '/api/servers/after-backup-sentinel')[0] === 200);
            Runtime::closeResources([$sourceApi, $source]);
            $sourceApi = $source = null;
            Runtime::requireThat(iterator_count(new \FilesystemIterator($restoredDirectory)) === 0);
            $restored = new Cluster($restoredDirectory, $rqlited, $nodes);
            Runtime::requireThat(file_get_contents($sourceDirectory . '/ca.crt') !== file_get_contents($restoredDirectory . '/ca.crt'));
            $leader = $restored->start();
            Runtime::requireThat(self::queryRows($restored, $leader, "SELECT name FROM sqlite_master WHERE type='table'") === []);
            [$status, $payload] = self::binaryRequest($restored, $leader, 'POST', '/db/load', $backup);
            $response = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            Runtime::requireThat($status === 200
                && !isset($response['error'])
                && !array_any($response['results'] ?? [], static fn(array $result): bool => isset($result['error'])));
            self::waitRows($restored, $rows, $migrations);
            $restoredApi = new Api(
                $restored,
                $server,
                'restored-api',
                array_map(
                    static fn(int $index): array => [$index, $restored->httpPorts[$index]],
                    range(0, $nodes - 1),
                ),
            );
            Runtime::requireThat($restoredApi->request('GET', '/ready')[0] === 200);
            foreach ($public as $identifier => $expected) {
                Runtime::requireThat(self::lookup($restoredApi, $identifier) == $expected);
            }
            Runtime::requireThat($restoredApi->request('GET', '/api/servers/after-backup-sentinel')[0] === 404);
            $wrongOwner = array_replace($values[0], ['apiKey' => str_repeat('bd', 32)]);
            Runtime::requireThat($restoredApi->request('POST', '/api/servers/register', $wrongOwner)[0] === 403);
            Runtime::requireThat(self::lookup($restoredApi, $values[0]['publicId']) == $public[$values[0]['publicId']]);
            Runtime::requireThat(self::queryRows($restored, $leader, self::REGISTRY_QUERY) == $rows);
            [$status, $body] = $restoredApi->request('POST', '/api/servers/register', $values[0]);
            Runtime::requireThat($status === 200 && $body['data']['revision'] === 3);
            $heartbeat = self::lookup($restoredApi, $values[0]['publicId']);
            Runtime::requireThat($heartbeat['revision'] === 3 && $heartbeat['lastHeartbeatMs'] >= $public[$values[0]['publicId']]['lastHeartbeatMs']);
            self::waitRows($restored, self::queryRows($restored, $leader, self::REGISTRY_QUERY), $migrations);
            echo "PASS: local binary backup integrity, fresh {$nodes}-voter restore, snapshot boundary, ownership and voter convergence\n";
        } catch (\Throwable $failure) {
            $scenarioFailure = $failure;
            throw $failure;
        } finally {
            try {
                Runtime::closeAfter($scenarioFailure, [$restoredApi, $restored, $sourceApi, $source]);
            } finally {
                try {
                    if ($diagnostics !== null) {
                        ClusterContract::exportDiagnostics($sourceDirectory, $diagnostics . '/source');
                        ClusterContract::exportDiagnostics($restoredDirectory, $diagnostics . '/restored');
                    }
                } finally {
                    Runtime::removeTree($root);
                }
            }
        }
    }
}
