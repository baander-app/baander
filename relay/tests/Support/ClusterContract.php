<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class ClusterContract
{
    /**
     * @return array{publicId:string,url:string,name:string,version:string,apiKey:string} */
    public static function registration(string $identifier): array
    {
        return [
            'publicId' => $identifier,
            'url' => "https://{$identifier}.baander.app",
            'name' => $identifier,
            'version' => '1.0.0',
            'apiKey' => str_repeat('ac', 32),
        ];
    }
    /**
     * @param array<string,array<mixed>> $acknowledged */
    public static function verify(Api $api, array $acknowledged): void
    {
        foreach ($acknowledged as $identifier => $expected) {
            [$status, $body] = $api->request('GET', '/api/servers/' . $identifier);
            Runtime::requireThat(
                $status === 200 && array_keys($body) === ['data'] && $body['data'] == $expected,
                'Public snapshot mismatch for ' . $identifier,
            );
        }
    }
    /**
     * @return array<string,mixed>|null */
    public static function localRow(Cluster $cluster, int $voter, string $identifier): ?array
    {
        [$status, $result] = $cluster->request(
            $voter,
            '/db/query?level=none&associative',
            [[
                'SELECT public_id, credential_digest, url, name, version, created_ms, updated_ms, last_seen_ms, revision FROM registries WHERE public_id=?',
                $identifier,
            ]],
        );
        $context = "Local diagnostic voter {$voter}, identity {$identifier}";
        Runtime::requireThat(
            $status === 200 && !isset($result['error']) && isset($result['results']) && count($result['results']) === 1,
            "{$context}: invalid results",
        );
        $statement = $result['results'][0];
        $types = [
            'public_id' => 'text',
            'credential_digest' => 'text',
            'url' => 'text',
            'name' => 'text',
            'version' => 'text',
            'created_ms' => 'integer',
            'updated_ms' => 'integer',
            'last_seen_ms' => 'integer',
            'revision' => 'integer',
        ];
        Runtime::requireThat(
            !isset($statement['error']) && ($statement['types'] ?? null) == $types,
            "{$context}: invalid column types",
        );
        $rows = $statement['rows'] ?? [];
        Runtime::requireThat(is_array($rows) && count($rows) <= 1, "{$context}: invalid row count");
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        Runtime::requireThat(
            count($row) === count($types) && array_all(array_keys($types), static fn($field) => array_key_exists($field, $row)),
            "{$context}: invalid fields",
        );
        foreach ($types as $field => $type) {
            Runtime::requireThat(
                $type === 'integer' ? is_int($row[$field]) : is_string($row[$field]),
                "{$context}: invalid {$field}",
            );
        }
        return $row;
    }
    /**
     * @param list<int> $indices
     * @param array<string,array<mixed>> $expectedRows */
    public static function waitConverged(Cluster $cluster, array $indices, int $acknowledgedIndex, array $expectedRows = []): int
    {
        $until = Runtime::now() + 30;
        $last = '';
        do {
            try {
                $states = array_map(static fn($index) => $cluster->request($index, '/status')[1]['store'], $indices);
                $leaders = array_unique(array_map(static fn($state) => $state['leader']['node_id'], $states));
                if (count($leaders) === 1
                    && reset($leaders) !== ''
                    && array_all($states, static fn($state) => (int) $state['db_applied_index'] >= $acknowledgedIndex)) {
                    foreach ($indices as $voter) {
                        foreach ($expectedRows as $id => $expected) {
                            Runtime::requireThat(
                                self::localRow($cluster, $voter, $id) == $expected,
                                "voter {$voter} identity {$id} row mismatch",
                            );
                        }
                    }
                    return (int) substr((string) reset($leaders), 5);
                }
            } catch (\Throwable $e) {
                $last = $e->getMessage();
            }
            usleep(50000);
        } while (Runtime::now() < $until);
        throw new \RuntimeException('Voters did not rejoin/apply acknowledged state within 30 seconds. ' . $last);
    }
    public static function exportDiagnostics(string $directory, ?string $destination): void
    {
        if ($destination === null) {
            return;
        }
        if (!is_dir($destination)) {
            Runtime::requireThat(mkdir($destination, 0700, true), 'Cannot create diagnostics directory.');
        }
        foreach (glob($directory . '/*.log') ?: [] as $path) {
            Runtime::requireThat(copy($path, $destination . '/' . basename($path)), 'Cannot retain diagnostics.');
        }
    }
    public static function verifyCrossApiOwnership(Api $first, Api $second, Cluster $cluster, int $voter): void
    {
        $owner = array_replace(self::registration('cross-api-owner'), ['apiKey' => str_repeat('34', 32)]);
        $target = '/api/servers/' . $owner['publicId'];
        [$status, $claimed] = $first->request('POST', '/api/servers/register', $owner);
        Runtime::requireThat($status === 200 && $claimed['data']['revision'] === 1);
        $stored = static function () use ($cluster, $voter, $owner): array {
            [$status, $result] = $cluster->request(
                $voter,
                '/db/query?level=linearizable&associative',
                [['SELECT * FROM registries WHERE public_id=?', $owner['publicId']]],
            );
            Runtime::requireThat($status === 200 && count($result['results'][0]['rows']) === 1);
            return $result['results'][0]['rows'][0];
        };
        $before = $stored();
        $beforePublic = $first->request('GET', $target)[1]['data'];
        $takeover = array_replace(
            $owner,
            [
                'apiKey' => str_repeat('56', 32),
                'url' => 'https://cross-api-hostile.baander.app',
                'name' => 'Hostile replacement',
                'version' => '99.0.0',
            ],
        );
        Runtime::requireThat(
            $second->request('POST', '/api/servers/register', $takeover)[0] === 403 && $stored() == $before,
            'Cross-API takeover changed owner row.',
        );
        foreach ([$first, $second] as $api) {
            [$status, $body] = $api->request('GET', $target);
            Runtime::requireThat($status === 200 && $body['data'] == $beforePublic);
        }
        $capability = Enrollment::issueToken($cluster->enrollmentKey, $owner, (int) (microtime(true) * 1000) + 60000);
        $different = array_replace($owner, ['publicId' => 'cross-api-other-identity']);
        Runtime::requireThat($second->request('POST', '/api/servers/register', $different, $capability)[0] === 403);
        foreach ([$first, $second] as $api) {
            Runtime::requireThat($api->request('GET', '/api/servers/' . $different['publicId'])[0] === 404);
        }
        Runtime::requireThat($stored() == $before);
        [$status, $retried] = $second->request('POST', '/api/servers/register', $owner, false);
        Runtime::requireThat($status === 200 && $retried['data']['revision'] === 2);
        foreach ([$first, $second] as $api) {
            [$status, $body] = $api->request('GET', $target);
            Runtime::requireThat($status === 200 && $body['data']['revision'] === 2);
            foreach (['publicId', 'url', 'name', 'version'] as $field) {
                Runtime::requireThat($body['data'][$field] === $owner[$field]);
            }
        }
        Runtime::requireThat($stored()['credential_digest'] === $before['credential_digest']);
        echo "PASS: distinct-owner cross-API claims, capability binding and owner retry\n";
    }
    /**
     * @param array<mixed> $body
     * @param array<string,string> $headers */
    public static function requireUnavailable(int $status, array $body, array $headers): void
    {
        $errors = [
            'Authoritative registry unavailable.',
            'Authoritative database result unavailable.',
            'Authoritative registry result unavailable.',
            'Database request budget exhausted.',
            'Database request deadline exceeded.',
            'Registry query result unavailable.',
            'Registry result unavailable.',
        ];
        Runtime::requireThat(
            $status === 503
                && array_keys($body) === ['error']
                && in_array($body['error'], $errors, true)
                && (array_change_key_case($headers)['retry-after'] ?? null) === '1',
            'Invalid public unavailable response: ' . json_encode([$status, $body, $headers]),
        );
    }
    public static function run(string $rqlited, string $server, int $nodes, ?string $diagnostics = null): void
    {
        Runtime::requireThat(in_array($nodes, [3, 5], true), 'Node count must be 3 or 5.');
        $directory = Runtime::temporaryDirectory("baander-registry-{$nodes}-voters-");
        $cluster = null;
        $apis = [];
        $observer = null;
        $scenarioFailure = null;
        try {
            $cluster = new Cluster($directory, $rqlited, $nodes);
            $initial = $cluster->start();
            $minority = [$initial];
            if ($nodes === 5) {
                $minority[] = array_values(array_diff(range(0, $nodes - 1), [$initial]))[0];
            }
            $majority = array_values(array_diff(range(0, $nodes - 1), $minority));
            $observer = new Observer($cluster, $initial);
            $endpoints = [[$initial, $observer->port]];
            foreach ([...$majority, ...array_diff($minority, [$initial])] as $index) {
                $endpoints[] = [$index, $cluster->httpPorts[$index]];
            }
            $pinned = new Api($cluster, $server, 'pinned', $endpoints);
            $apis[] = $pinned;
            $minorityApi = new Api(
                $cluster,
                $server,
                'minority',
                array_map(static fn($i) => [$i, $cluster->httpPorts[$i]], $minority),
            );
            $apis[] = $minorityApi;
            $majorityApi = new Api(
                $cluster,
                $server,
                'majority',
                array_map(static fn($i) => [$i, $cluster->httpPorts[$i]], $majority),
            );
            $apis[] = $majorityApi;
            self::verifyCrossApiOwnership($pinned, $majorityApi, $cluster, $initial);
            $acknowledged = [];
            $write = static function (Api $api, string $identifier, ?array $value = null, bool $enrollment = true) use (&$acknowledged): void {
                $value ??= self::registration($identifier);
                [$status, $body] = $api->request('POST', '/api/servers/register', $value, $enrollment);
                Runtime::requireThat($status === 200, "Expected committed registration: {$identifier} ({$status})");
                $acknowledged[$identifier] = [
                    'publicId' => $identifier,
                    'url' => $value['url'],
                    'name' => $value['name'],
                    'version' => $value['version'],
                    'revision' => $body['data']['revision'],
                    'updatedAtMs' => $body['data']['updatedAtMs'],
                    'lastHeartbeatMs' => $body['data']['lastHeartbeatMs'],
                ];
            };
            for ($i = 0; $i < 4; ++$i) {
                $write($pinned, "before-partition-{$i}");
            }
            self::verify($pinned, $acknowledged);
            $seed = 'before-partition-0';
            $new = 'during-partition-0';
            $baseline = self::localRow($cluster, $initial, $seed);
            $digest = hash('sha256', self::registration($seed)['apiKey']);
            Runtime::requireThat(
                $baseline !== null && $baseline['credential_digest'] === $digest,
                'Seed credential digest mismatch.',
            );
            $baselineIndex = (int) $cluster->request($initial, '/status')[1]['store']['db_applied_index'];
            self::waitConverged($cluster, range(0, $nodes - 1), $baselineIndex, [$seed => $baseline]);
            $connection = Http::connect('api.registry.baander.app', '127.0.0.1', $pinned->port, $cluster->apiTls, 6);
            $observer->clear();
            $partitionStarted = Runtime::now();
            $cluster->network->partition($minority, $nodes === 5);
            try {
                $uncertain = self::registration('uncertain-partition');
                [$status, $body, $headers] = Http::exchange(
                    $connection,
                    'api.registry.baander.app',
                    'POST',
                    '/api/servers/register',
                    json_encode($uncertain, JSON_THROW_ON_ERROR),
                    [
                        'Content-Type' => 'application/json',
                        'X-Baander-Enrollment' => Enrollment::issueToken($cluster->enrollmentKey, $uncertain, (int) (microtime(true) * 1000) + 60000),
                    ],
                    6,
                );
                $body = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                Runtime::requireThat($status === 503 && ($headers['retry-after'] ?? null) === '1' && !isset($body['url']));
            } finally {
                fclose($connection);
            }
            $observed = $observer->snapshot();
            Runtime::requireThat(
                count($observed) === 1 && $observed[0]['error'] && in_array($observed[0]['status'], [200, 503], true),
                'Uncertain write was replayed or lacked upstream error: ' . json_encode($observed),
            );
            echo 'PASS: real minority upstream HTTP' . $observed[0]['status'] . " error returns public503 without write replay\n";
            $newLeader = $cluster->waitLeader($majority, 30);
            Runtime::requireThat(Runtime::now() - $partitionStarted <= 30, 'Partition leader recovery exceeded 30 seconds.');
            $majorityApi->waitReady();
            self::verify($majorityApi, $acknowledged);
            $until = Runtime::now() + 30;
            do {
                $nextStatus = $pinned->request('GET', '/api/servers/' . $seed)[0];
                if ($nextStatus === 200) {
                    break;
                }
                Runtime::requireThat($nextStatus === 503, 'Endpoint recovery returned an unexpected status.');
            } while (Runtime::now() < $until);
            Runtime::requireThat($nextStatus === 200, 'Configured healthy majority endpoints remained unavailable.');
            echo "PASS: configured endpoint recovery within30s\n";
            foreach ($minority as $voter) {
                [$status, $body] = $cluster->request($voter, '/db/query?level=linearizable&associative', ['SELECT COUNT(*) AS total FROM registries']);
                Runtime::requireThat(
                    $status !== 200 || isset($body['error']) || array_any($body['results'] ?? [], static fn(array $result): bool => isset($result['error'])),
                    'Minority served an authoritative read.',
                );
            }
            $updated = array_replace(
                self::registration($seed),
                [
                    'url' => 'https://updated-seed.baander.app',
                    'name' => 'Updated partition seed',
                    'version' => '2.0.0',
                ],
            );
            $write($majorityApi, $seed, $updated, false);
            Runtime::requireThat($acknowledged[$seed]['revision'] === $baseline['revision'] + 1);
            for ($number = 0; $number < 4; ++$number) {
                $write($majorityApi, 'during-partition-' . $number);
            }
            $expected = [];
            foreach ($acknowledged as $identifier => $public) {
                $row = self::localRow($cluster, $newLeader, $identifier);
                Runtime::requireThat(
                    $row !== null && $row['credential_digest'] === $digest,
                    'Acknowledged row missing or owner changed.',
                );
                foreach ([
                    'publicId' => 'public_id',
                    'url' => 'url',
                    'name' => 'name',
                    'version' => 'version',
                    'revision' => 'revision',
                    'updatedAtMs' => 'updated_ms',
                    'lastHeartbeatMs' => 'last_seen_ms',
                ] as $publicField => $storedField) {
                    Runtime::requireThat(
                        $row[$storedField] === $public[$publicField],
                        "Acknowledged {$identifier} field {$storedField} mismatch",
                    );
                }
                $expected[$identifier] = $row;
            }
            Runtime::requireThat($expected[$seed]['created_ms'] === $baseline['created_ms']
                && $expected[$seed]['updated_ms'] >= $baseline['updated_ms']
                && $expected[$seed]['last_seen_ms'] >= $baseline['last_seen_ms']);
            $index = (int) $cluster->request($newLeader, '/status')[1]['store']['db_applied_index'];
            self::waitConverged($cluster, $majority, $index, $expected);
            foreach ($minority as $voter) {
                Runtime::requireThat(
                    self::localRow($cluster, $voter, $seed) == $baseline && self::localRow($cluster, $voter, $new) === null,
                    'Minority did not retain obsolete state.',
                );
            }
            foreach ([$seed, $new] as $identifier) {
                self::requireUnavailable(...$minorityApi->request('GET', '/api/servers/' . $identifier));
            }
            self::requireUnavailable(...$minorityApi->request('GET', '/ready'));
            [$health, $body] = $minorityApi->request('GET', '/health');
            Runtime::requireThat($health === 200 && $body == ['data' => ['alive' => true]]);
            self::verify($majorityApi, $acknowledged);
            self::verify($pinned, $acknowledged);
            $cluster->network->heal();
            $healed = self::waitConverged($cluster, range(0, $nodes - 1), $index, $expected);
            $minorityApi->waitReady();
            self::verify($minorityApi, $acknowledged);
            echo 'PASS: ' . count($majority) . '–' . count($minority) . " partition, majority commits, leader recovery and voter rejoin\n";
            $failures = [$healed];
            if ($nodes === 5) {
                $failures[] = array_values(array_diff(range(0, $nodes - 1), [$healed]))[0];
            }
            $started = Runtime::now();
            foreach ($failures as $voter) {
                $cluster->processes[$voter]->signal(9);
                $cluster->processes[$voter]->wait(3);
            }
            $remaining = array_values(array_diff(range(0, $nodes - 1), $failures));
            $surviving = $cluster->waitLeader($remaining, 30);
            Runtime::requireThat(Runtime::now() - $started <= 30);
            $survivor = new Api(
                $cluster,
                $server,
                'survivors',
                array_map(static fn($i) => [$i, $cluster->httpPorts[$i]], $remaining),
            );
            $apis[] = $survivor;
            self::verify($survivor, $acknowledged);
            $write($survivor, 'after-voter-crash');
            $finalIndex = (int) $cluster->request($surviving, '/status')[1]['store']['db_applied_index'];
            foreach ($failures as $voter) {
                $cluster->startNode($voter);
            }
            $recovered = self::waitConverged($cluster, range(0, $nodes - 1), $finalIndex);
            self::verify($survivor, $acknowledged);
            echo 'PASS: no acknowledged loss after ' . count($failures) . " abrupt voter failures, restart and catch-up\n";
            $absent = 'absent-before-quorum-loss';
            $pinned->waitReady();
            Runtime::requireThat($pinned->request('GET', '/api/servers/' . $absent)[0] === 404);
            $stopped = [
                $recovered,
                ...array_slice(array_values(array_diff(range(0, $nodes - 1), [$recovered])), 0, intdiv($nodes, 2)),
            ];
            foreach ($stopped as $voter) {
                $cluster->processes[$voter]->signal(9);
                $cluster->processes[$voter]->wait(3);
            }
            Runtime::requireThat(count($stopped) === intdiv($nodes, 2) + 1);
            $outage = Runtime::now();
            foreach ([
                ['GET', '/api/servers/before-partition-0', null],
                ['GET', '/api/servers/' . $absent, null],
                ['POST', '/api/servers/register', self::registration('uncertain-quorum-loss')],
                ['GET', '/ready', null],
            ] as [$method, $target, $payload]) {
                $started = Runtime::now();
                self::requireUnavailable(...$pinned->request($method, $target, $payload));
                Runtime::requireThat(
                    Runtime::now() - $started < 6 && $pinned->process->poll() === null,
                    'No-quorum request exceeded deadline or API exited.',
                );
                $started = Runtime::now();
                [$status, $body] = $pinned->request('GET', '/health');
                Runtime::requireThat(Runtime::now() - $started < 6 && $status === 200 && $body == ['data' => ['alive' => true]]);
            }
            Runtime::requireThat(Runtime::now() - $outage < 30);
            $started = Runtime::now();
            foreach ($stopped as $voter) {
                $cluster->startNode($voter);
            }
            self::waitConverged($cluster, range(0, $nodes - 1), $finalIndex);
            $pinned->waitReady();
            Runtime::requireThat(Runtime::now() - $started <= 30, 'Quorum recovery exceeded 30 seconds.');
            self::verify($pinned, $acknowledged);
            $write($pinned, 'after-quorum-restoration');
            self::verify($pinned, $acknowledged);
            echo 'PASS: ' . count($stopped) . "/{$nodes} voters stopped, public 503 and live health, persisted quorum recovery within 30s without acknowledged loss\n";
        } catch (\Throwable $failure) {
            $scenarioFailure = $failure;
            self::exportDiagnostics($directory, $diagnostics ?? "/tmp/registry-cluster-{$nodes}-diagnostics");
            throw $failure;
        } finally {
            try {
                Runtime::closeAfter($scenarioFailure, [...array_reverse($apis), $observer, $cluster]);
            } finally {
                try {
                    self::exportDiagnostics($directory, $diagnostics);
                } finally {
                    Runtime::removeTree($directory);
                }
            }
        }
    }
}
