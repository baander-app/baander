<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

use Baander\Registry\Qualification\BoundedStorage;

final class StorageContract
{
    public const int MAX_REQUESTS = 2048;
    public const int PRESSURE_SECONDS = 120;
    /**
     * @return array{publicId:string,url:string,name:string,version:string,apiKey:string}
     */
    public static function pressureRegistration(int $number): array
    {
        $identifier = sprintf('storage-pressure-%04d', $number);
        $path = '';
        for ($index = 0; strlen($path) < 2000; ++$index) {
            $path .= hash('sha256', $identifier . ':' . $index);
        }
        $url = "https://{$identifier}.baander.app/";
        return [
            'publicId' => $identifier,
            'url' => $url . substr($path, 0, 2048 - strlen($url)),
            'name' => substr($identifier . '-' . $path, 0, 128),
            'version' => substr($path, 0, 64),
            'apiKey' => bin2hex(random_bytes(32)),
        ];
    }
    /**
     * @param array<string,array<string,mixed>> $ledger
     * @param array{publicId:string,apiKey:string,url:string,name:string,version:string} $submitted
     * @param array<string,mixed> $body
     */
    public static function acknowledge(array &$ledger, array $submitted, array $body): void
    {
        $data = $body['data'] ?? [];
        $keys = array_keys($data);
        sort($keys);
        Runtime::requireThat(
            array_keys($body) === ['data'] && $keys === ['lastHeartbeatMs', 'publicId', 'registered', 'revision', 'updatedAtMs'],
            'Invalid acknowledgement envelope.',
        );
        Runtime::requireThat(
            $data['registered'] === true
                && $data['publicId'] === $submitted['publicId']
                && $data['revision'] === 1
                && is_int($data['updatedAtMs'])
                && $data['updatedAtMs'] >= 0
                && $data['lastHeartbeatMs'] === $data['updatedAtMs'],
            'Invalid acknowledgement data.',
        );
        $ledger[$submitted['publicId']] = [
            'public_id' => $submitted['publicId'],
            'credential_digest' => hash('sha256', $submitted['apiKey']),
            'url' => $submitted['url'],
            'name' => $submitted['name'],
            'version' => $submitted['version'],
            'created_ms' => $data['updatedAtMs'],
            'updated_ms' => $data['updatedAtMs'],
            'last_seen_ms' => $data['lastHeartbeatMs'],
            'revision' => 1,
        ];
    }
    /**
     * @return list<string>
     */
    public static function requireStorageError(string $text): array
    {
        $causes = [];
        if (preg_match('/no space left on device|\bENOSPC\b/i', $text)) {
            $causes[] = 'voter storage: ENOSPC (no space left on device)';
        }
        if (preg_match('/database or disk is full|\bSQLITE_FULL\b/i', $text)) {
            $causes[] = 'voter SQLite: SQLITE_FULL (database or disk is full)';
        }
        Runtime::requireThat($causes !== [], 'No voter storage diagnostic identifies ENOSPC or SQLite FULL');
        sort($causes);
        return $causes;
    }
    /**
     * @param array<string,array<string,mixed>> $ledger
     * @param list<int> $indices
     */
    public static function verifyRows(Cluster $cluster, array $indices, array $ledger): void
    {
        foreach ($indices as $voter) {
            foreach ($ledger as $identifier => $expected) {
                Runtime::requireThat(
                    ClusterContract::localRow($cluster, $voter, $identifier) == $expected,
                    "voter {$voter} identity {$identifier} ledger mismatch",
                );
            }
        }
    }
    /**
     * @param array<string,array<string,mixed>> $ledger
     */
    public static function verifyPublic(Api $api, array $ledger): void
    {
        foreach ($ledger as $identifier => $row) {
            [$status, $body] = $api->request('GET', '/api/servers/' . $identifier);
            $expected = [
                'publicId' => $identifier,
                'url' => $row['url'],
                'name' => $row['name'],
                'version' => $row['version'],
                'updatedAtMs' => $row['updated_ms'],
                'lastHeartbeatMs' => $row['last_seen_ms'],
                'revision' => $row['revision'],
            ];
            Runtime::requireThat(
                $status === 200 && $body == ['data' => $expected],
                'Public ledger mismatch: ' . $identifier,
            );
        }
    }
    /**
     * @param array<string,array<string,mixed>> $ledger
     */
    private static function waitRecovered(Cluster $cluster, Api $api, array $ledger, float $deadline): void
    {
        $last = '';
        do {
            try {
                Runtime::requireThat(
                    $api->process->poll() === null && $api->request('GET', '/ready')[0] === 200,
                    'API exited or not ready.',
                );
                self::verifyRows($cluster, [0, 1, 2], $ledger);
                self::verifyPublic($api, $ledger);
                Runtime::requireThat(Runtime::now() <= $deadline, 'Recovery exceeded 30 seconds.');
                return;
            } catch (\Throwable $failure) {
                $last = $failure->getMessage();
            }
            usleep(50000);
        } while (Runtime::now() < $deadline);
        throw new \RuntimeException('Recovery failed within 30 seconds: ' . $last);
    }
    public static function run(string $rqlited, string $server, string $storageRoot): void
    {
        $storage = new BoundedStorage($storageRoot);
        $resources = [$storage];
        $artifacts = $directory = null;
        $succeeded = false;
        $scenarioFailure = null;
        try {
            $artifacts = Runtime::temporaryDirectory('baander-registry-storage-evidence-');
            $directory = Runtime::temporaryDirectory('baander-registry-storage-');
            $evidence = ['storage' => $storage->evidence];
            $ledger = [];
            $record = static function (string $phase, array $details = []) use (&$evidence, &$ledger, $artifacts): void {
                $evidence = array_replace($evidence, ['phase' => $phase], $details);
                file_put_contents(
                    $artifacts . '/evidence.json',
                    json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
                );
                file_put_contents($artifacts . '/ledger.json', json_encode($ledger, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            };
            $record('validated');
            $cluster = new Cluster($directory, $rqlited, 3, $storage->directory);
            array_unshift($resources, $cluster);
            $cluster->start();
            $api = new Api(
                $cluster,
                $server,
                'storage-api',
                array_map(static fn(int $index): array => [$index, $cluster->httpPorts[$index]], [0, 1, 2]),
            );
            array_unshift($resources, $api);
            for ($number = 0; $number < 3; ++$number) {
                $submitted = array_replace(self::pressureRegistration($number), ['publicId' => "storage-seed-{$number}"]);
                [$status, $body] = $api->request('POST', '/api/servers/register', $submitted);
                Runtime::requireThat($status === 200, "Seed registration failed: HTTP {$status}");
                self::acknowledge($ledger, $submitted, $body);
                $record('seed');
            }
            self::waitRecovered($cluster, $api, $ledger, Runtime::now() + 30);
            $evidence['before_fill'] = BoundedStorage::usage($storageRoot);
            $offsets = [];
            foreach (glob($directory . '/node-*.log') as $path) {
                $offsets[$path] = filesize($path);
            }
            $record('filled', $storage->fill());
            $started = Runtime::now();
            $uncertain = null;
            for ($number = 0; $number < self::MAX_REQUESTS && Runtime::now() - $started < self::PRESSURE_SECONDS; ++$number) {
                $submitted = self::pressureRegistration($number);
                [$status, $body, $headers] = $api->request('POST', '/api/servers/register', $submitted);
                $elapsed = Runtime::now() - $started;
                Runtime::requireThat($elapsed <= self::PRESSURE_SECONDS, 'Pressure time ceiling exceeded.');
                if ($status === 200) {
                    self::acknowledge($ledger, $submitted, $body);
                    $record('acknowledged', ['pressure_requests' => $number + 1, 'pressure_seconds' => $elapsed]);
                    continue;
                }
                ClusterContract::requireUnavailable($status, $body, $headers);
                $record(
                    'unavailable',
                    [
                        'pressure_requests' => $number + 1,
                        'pressure_seconds' => $elapsed,
                        'response' => $body,
                        'status' => $status,
                        'retry_after' => '1',
                    ],
                );
                $uncertain = $submitted;
                break;
            }
            Runtime::requireThat($uncertain !== null, 'Pressure request/time ceiling reached without a qualifying 503.');
            Runtime::requireThat($api->process->poll() === null && $api->request('GET', '/health')[0] === 200);
            $evidence['pressure_readiness'] = $api->request('GET', '/ready')[0];
            $diagnostics = '';
            foreach ($offsets as $path => $offset) {
                $diagnostics .= file_get_contents($path, offset: $offset);
            }
            $record('storage_failure', ['storage_errors' => self::requireStorageError($diagnostics)]);
            $recoveryStarted = Runtime::now();
            $storage->reclaim();
            $record('reclaimed', ['after_reclaim' => BoundedStorage::usage($storageRoot)]);
            $cluster->stopNodes();
            foreach ([0, 1, 2] as $index) {
                $cluster->startNode($index);
            }
            self::waitRecovered($cluster, $api, $ledger, $recoveryStarted + 30);
            $record('recovered', ['recovery_seconds' => Runtime::now() - $recoveryStarted]);
            $leader = $cluster->waitLeader();
            $before = ClusterContract::localRow($cluster, $leader, $uncertain['publicId']);
            if ($before !== null) {
                Runtime::requireThat(
                    $before['credential_digest'] === hash('sha256', $uncertain['apiKey']) && $before['revision'] === 1,
                    'Uncertain identity lost ownership.',
                );
                foreach (['url', 'name', 'version'] as $field) {
                    Runtime::requireThat($before[$field] === $uncertain[$field]);
                }
                Runtime::requireThat($before['created_ms'] === $before['updated_ms'] && $before['updated_ms'] === $before['last_seen_ms']);
            }
            [$status, $body] = $api->request('POST', '/api/servers/register', $uncertain);
            Runtime::requireThat($status === 200, 'Original credential retry failed.');
            if ($before === null) {
                self::acknowledge($ledger, $uncertain, $body);
            } else {
                $data = $body['data'];
                $keys = array_keys($data);
                sort($keys);
                Runtime::requireThat(array_keys($body) === ['data'] && $keys === ['lastHeartbeatMs', 'publicId', 'registered', 'revision', 'updatedAtMs']);
                Runtime::requireThat($data['registered'] === true
                    && $data['publicId'] === $uncertain['publicId']
                    && is_int($data['updatedAtMs'])
                    && $data['revision'] === $before['revision'] + 1
                    && $data['updatedAtMs'] >= $before['updated_ms']
                    && $data['lastHeartbeatMs'] === $data['updatedAtMs']);
                $ledger[$uncertain['publicId']] = array_replace(
                    $before,
                    [
                        'revision' => $data['revision'],
                        'updated_ms' => $data['updatedAtMs'],
                        'last_seen_ms' => $data['lastHeartbeatMs'],
                    ],
                );
            }
            $record('retried', ['uncertain_was_present' => $before !== null]);
            $fresh = array_replace(self::pressureRegistration(0), ['publicId' => 'storage-after-recovery']);
            [$status, $body] = $api->request('POST', '/api/servers/register', $fresh);
            Runtime::requireThat($status === 200);
            self::acknowledge($ledger, $fresh, $body);
            $record('fresh_registered');
            self::waitRecovered($cluster, $api, $ledger, Runtime::now() + 30);
            $record('verified', ['acknowledged_rows' => count($ledger)]);
            $closing = $resources;
            $resources = [];
            Runtime::closeResources($closing);
            $succeeded = true;
            echo 'PASS: real tmpfs exhaustion, storage-caused public 503, exact voter/public recovery ' . json_encode($evidence, JSON_THROW_ON_ERROR) . "\n";
        } catch (\Throwable $failure) {
            $scenarioFailure = $failure;
            throw $failure;
        } finally {
            try {
                Runtime::closeAfter($scenarioFailure, $resources);
            } finally {
                if ($directory !== null) {
                    if ($artifacts !== null) {
                        foreach (glob($directory . '/*.log') as $path) {
                            copy($path, $artifacts . '/' . basename($path));
                        }
                    }
                    Runtime::removeTree($directory);
                }
                if ($artifacts !== null) {
                    if ($succeeded) {
                        Runtime::removeTree($artifacts);
                    } else {
                        fwrite(STDERR, "Full-disk contract failure evidence: {$artifacts}\n");
                    }
                }
            }
        }
    }
}
