<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Baander\RegistryTests;

final class HttpContract
{
    private string $directory;
    private string $enrollmentKey = 'native-contract-enrollment-key-32-bytes';
    /**
     * @var list<string>
     */
    private array $secrets = [];
    /**
     * @var array<string,mixed>
     */
    private array $config;
    /**
     * @var array<string,mixed>
     */
    private array $tls;
    /**
     * @var array<string,mixed>
     */
    private array $clientTls;
    private int $port;
    private int $databasePort;
    private string $server;
    private string $statePath;
    /**
     * @var list<Process>
     */
    private array $processes = [];
    /**
     * @var array<string,Process>
     */
    private array $children = [];

    public static function main(): void
    {
        $options = getopt('', ['server:', 'rqlited:']);
        Runtime::requireThat(isset($options['server'], $options['rqlited']), 'Required: --server and --rqlited');
        $suite = new self();
        $suite->directory = Runtime::temporaryDirectory('baander-registry-http-');
        try {
            $suite->execute($options['server'], $options['rqlited']);
        } catch (\Throwable $error) {
            foreach (['api', 'rqlite'] as $name) {
                $source = $suite->directory . '/' . $name . '.log';
                if (is_file($source)) {
                    $diagnostic = str_replace($suite->secrets, '[REDACTED]', file_get_contents($source));
                    file_put_contents('/tmp/registry-http-' . $name . '-failure.log', $diagnostic);
                }
            }
            throw $error;
        } finally {
            foreach ($suite->children as $child) {
                $child->close(false);
            }
            foreach (array_reverse($suite->processes) as $process) {
                $process->close(false);
            }
            Runtime::removeTree($suite->directory);
        }
    }

    private static function check(bool $condition, string $message): void
    {
        Runtime::requireThat($condition, $message);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function noSecrets(mixed $value): void
    {
        $serialized = is_string($value) ? $value : self::json($value);
        foreach ($this->secrets as $secret) {
            self::check(!str_contains($serialized, $secret), 'Registry exposed a credential, capability, key or digest.');
        }
    }

    /**
     * @return array<string,string>
     */
    private function headers(string $method, string $target, ?string $body, bool|string $enrollment): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($method === 'POST' && $target === '/api/servers/register') {
            $registration = json_decode($body ?? '', true);
            if (is_array($registration) && isset($registration['apiKey']) && is_string($registration['apiKey'])) {
                if (strlen($registration['apiKey']) === 64) {
                    $this->secrets[] = $registration['apiKey'];
                    $this->secrets[] = hash('sha256', $registration['apiKey']);
                }
                if ($enrollment) {
                    try {
                        $headers['X-Baander-Enrollment'] = Enrollment::issueToken($this->enrollmentKey, $registration, (int) (microtime(true) * 1000) + 60000);
                    } catch (\Throwable) {
                    }
                }
            }
        }
        if (is_string($enrollment)) {
            $headers['X-Baander-Enrollment'] = $enrollment;
        }
        if (isset($headers['X-Baander-Enrollment'])) {
            $this->secrets[] = $headers['X-Baander-Enrollment'];
        }
        return $headers;
    }

    /**
     * @return array{int,array<string,mixed>,array<string,string>}
     */
    private function request(string $method, string $target, ?string $body = null, ?int $port = null, bool|string $enrollment = true, ?string $source = null): array
    {
        $tls = $this->tls;
        if ($source !== null) {
            $tls['bindto'] = $source . ':0';
        }
        $result = Http::request(
            'api.registry.baander.app',
            '127.0.0.1',
            $port ?? $this->port,
            $tls,
            $method,
            $target,
            $body,
            $this->headers($method, $target, $body, $enrollment),
        );
        return $this->decode($result);
    }

    /**
     * @param array{int,string,array<string,string>} $result
     * @return array{int,array<string,mixed>,array<string,string>}
     */
    private function decode(array $result): array
    {
        [$status, $body, $headers] = $result;
        $this->noSecrets($body);
        $this->noSecrets($headers);
        return [
            $status,
            json_decode($body, true, 64, JSON_THROW_ON_ERROR),
            array_change_key_case($headers, CASE_LOWER),
        ];
    }

    private function socket(int $port): mixed
    {
        $context = stream_context_create(['ssl' => $this->tls + ['peer_name' => 'api.registry.baander.app', 'verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client('tls://127.0.0.1:' . $port, $code, $error, 3, STREAM_CLIENT_CONNECT, $context);
        self::check(is_resource($socket), 'Disposable TLS connection failed: ' . $error);
        stream_set_timeout($socket, 3);
        return $socket;
    }

    /**
     * @return array{int,array<string,mixed>}
     */
    private function raw(string $wire): array
    {
        $socket = $this->socket($this->port);
        try {
            $remaining = $wire;
            while ($remaining !== '') {
                $written = fwrite($socket, $remaining);
                self::check($written !== false && $written > 0, 'Raw TLS request write failed.');
                $remaining = substr($remaining, $written);
            }
            $response = '';
            while (!feof($socket)) {
                $chunk = fread($socket, 65536);
                self::check(
                    $chunk !== false && !stream_get_meta_data($socket)['timed_out'],
                    'Registry kept a raw request connection open.',
                );
                $response .= $chunk;
                self::check(strlen($response) <= 1048576, 'Raw response exceeded bound.');
            }
            [$head, $body] = explode("\r\n\r\n", $response, 2);
            preg_match('/^HTTP\/1\.[01] ([0-9]{3})/', $head, $match);
            self::check(isset($match[1]), 'Missing raw HTTP status.');
            $this->noSecrets($head);
            $this->noSecrets($body);
            return [(int) $match[1], json_decode($body, true, 64, JSON_THROW_ON_ERROR)];
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param array<string,mixed> $values
     */
    private function change(array $values): void
    {
        HttpProxy::state(
            $this->statePath,
            static function (array &$state) use ($values): void {
                $state = array_replace($state, $values);
            },
        );
    }

    /**
     * @param callable(array<string,mixed>):bool $predicate
     * @return array<string,mixed>
     */
    private function waitState(callable $predicate, float $timeout = 2): array
    {
        $until = microtime(true) + $timeout;
        do {
            $state = HttpProxy::state($this->statePath);
            if ($predicate($state)) {
                return $state;
            }
            usleep(10000);
        } while (microtime(true) < $until);
        throw new \RuntimeException('Disposable proxy fault did not reach the expected state.');
    }

    private function async(string $body, ?int $port = null): string
    {
        $path = $this->directory . '/request-' . bin2hex(random_bytes(6)) . '.json';
        $headers = $this->headers('POST', '/api/servers/register', $body, true);
        $input = $path . '.input';
        file_put_contents(
            $input,
            self::json([
                'path' => $path,
                'body' => $body,
                'port' => $port ?? $this->port,
                'tls' => $this->tls,
                'headers' => $headers,
            ]),
        );
        $this->children[$path] = new Process([PHP_BINARY, dirname(__DIR__) . '/run_http_contract.php', '--request-file', $input]);
        return $path;
    }

    /**
     * @return array{int,array<string,mixed>,array<string,string>}
     */
    private function await(string $path, float $timeout = 3): array
    {
        $until = microtime(true) + $timeout;
        while (!is_file($path) && microtime(true) < $until) {
            usleep(10000);
        }
        self::check(is_file($path), 'Concurrent request did not finish.');
        $result = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        self::check($this->children[$path]->wait(1) === 0, 'Concurrent request child failed.');
        $this->children[$path]->close();
        unset($this->children[$path]);
        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }
        return $this->decode($result['result']);
    }

    private function ready(int $port, float $timeout = 10): void
    {
        $until = microtime(true) + $timeout;
        do {
            try {
                if ($this->request('GET', '/ready', port: $port)[0] === 200) {
                    return;
                }
            } catch (\Throwable) {
            }
            usleep(50000);
        } while (microtime(true) < $until);
        throw new \RuntimeException('Native TLS registry never became ready.');
    }

    /**
     * @param array<string,mixed> $config
     */
    private function launch(array $config, string $name): Process
    {
        $path = $this->directory . '/' . $name . '.json';
        file_put_contents($path, self::json($config));
        return $this->processes[] = new Process([$this->server, '--config', $path], $this->directory . '/api.log');
    }

    /**
     * @return array{int,Process}
     */
    private function graceful(int $grace, string $name, ?int $deadline = 1000, ?int $databaseDeadline = 500): array
    {
        $config = $this->config;
        $port = Runtime::port();
        $config['api']['port'] = $port;
        $config['api']['shutdownGraceMs'] = $grace;
        if ($deadline === null) {
            unset($config['api']['deadlineMs']);
        } else {
            $config['api']['deadlineMs'] = $deadline;
        }
        if ($databaseDeadline === null) {
            unset($config['database']['deadlineMs']);
        } else {
            $config['database']['deadlineMs'] = $databaseDeadline;
        }
        $process = $this->launch($config, $name);
        $this->ready($port);
        return [$port, $process];
    }

    /**
     * @param list<mixed> $statements
     * @return array<string,mixed>
     */
    private function database(array $statements, bool $read = false): array
    {
        [$status, $body] = Http::request(
            'rqlite.baander.app',
            '127.0.0.1',
            $this->databasePort,
            $this->clientTls,
            'POST',
            $read ? '/db/query?level=linearizable&associative' : '/db/execute?transaction',
            self::json($statements),
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode('registry:disposable-test-secret'),
            ],
        );
        $result = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        self::check($status === 200 && isset($result['results']), 'Database request failed.');
        foreach ($result['results'] as $item) {
            self::check(!isset($item['error']), 'Database statement failed.');
        }
        return $result;
    }

    private function execute(string $server, string $rqlited): void
    {
        $this->server = $server;
        $d = $this->directory;
        Tls::authority($d);
        [$dbKey, $dbCertificate] = Tls::certificate($d, 'database', 'rqlite.baander.app');
        [$apiKey, $apiCertificate] = Tls::certificate($d, 'api', 'api.registry.baander.app');
        [$clientKey, $clientCertificate] = Tls::certificate($d, 'client');
        file_put_contents($d . '/password', "disposable-test-secret\n");
        file_put_contents(
            $d . '/auth.json',
            self::json([['username' => 'registry', 'password' => 'disposable-test-secret', 'perms' => ['all']]]),
        );
        file_put_contents($d . '/enrollment.key', $this->enrollmentKey);
        $this->secrets = [$this->enrollmentKey, 'disposable-test-secret', base64_encode('registry:disposable-test-secret')];
        $this->databasePort = Runtime::port();
        $this->port = Runtime::port();
        $raftPort = Runtime::port();
        $proxyPort = Runtime::port();
        $this->tls = ['cafile' => $d . '/ca.crt'];
        $this->clientTls = $this->tls + ['local_cert' => $clientCertificate, 'local_pk' => $clientKey];
        $this->statePath = $d . '/proxy-state.json';
        file_put_contents(
            $this->statePath,
            self::json([
                'delay' => 0,
                'malformedReadiness' => false,
                'arrivals' => 0,
                'armed' => false,
                'writes' => 0,
                'completed' => false,
                'error' => null,
            ]),
        );
        $proxyConfig = [
            'port' => $proxyPort,
            'databasePort' => $this->databasePort,
            'certificate' => $dbCertificate,
            'key' => $dbKey,
            'ca' => $d . '/ca.crt',
            'clientCertificate' => $clientCertificate,
            'clientKey' => $clientKey,
            'state' => $this->statePath,
            'log' => $d . '/proxy.log',
        ];
        file_put_contents($d . '/proxy.json', self::json($proxyConfig));
        $proxy = $this->processes[] = new Process(
            [PHP_BINARY, dirname(__DIR__) . '/run_http_contract.php', '--proxy-file', $d . '/proxy.json'],
            $d . '/proxy-process.log',
        );
        $this->config = [
            'database' => [
                'endpoints' => [['url' => 'https://rqlite.baander.app:' . $proxyPort, 'connectAddress' => '127.0.0.1']],
                'ca' => $d . '/ca.crt',
                'certificate' => $clientCertificate,
                'key' => $clientKey,
                'username' => 'registry',
                'passwordFile' => $d . '/password',
                'connections' => 2,
                'deadlineMs' => 500,
            ],
            'api' => [
                'enrollmentKeyFile' => $d . '/enrollment.key',
                'address' => '127.0.0.1',
                'port' => $this->port,
                'certificate' => $apiCertificate,
                'key' => $apiKey,
                'deadlineMs' => 1000,
            ],
        ];
        $db = $this->processes[] = new Process(
            [
                $rqlited,
                '-node-id',
                'registry-http-test',
                '-http-addr',
                '127.0.0.1:' . $this->databasePort,
                '-raft-addr',
                '127.0.0.1:' . $raftPort,
                '-http-cert',
                $dbCertificate,
                '-http-key',
                $dbKey,
                '-http-ca-cert',
                $d . '/ca.crt',
                '-http-verify-client',
                '-auth',
                $d . '/auth.json',
                $d . '/data',
            ],
            $d . '/rqlite.log',
        );
        $api = $this->launch($this->config, 'config');
        $this->ready($this->port, 15);
        $registration = [
            'publicId' => 'server-one',
            'url' => 'https://music.baander.app',
            'name' => 'Baander native API',
            'version' => '1.0.0',
            'apiKey' => str_repeat('ab', 32),
        ];
        $encoded = self::json($registration);
        $owned = $registration;
        $timestamp = (int) (microtime(true) * 1000);
        $invalid = [
            false,
            'v1.bad',
            Enrollment::issueToken($this->enrollmentKey, $registration, $timestamp - 1),
            Enrollment::issueToken($this->enrollmentKey, $registration, $timestamp + 600000),
            Enrollment::issueToken($this->enrollmentKey, array_replace($registration, ['publicId' => 'wrong-id']), $timestamp + 60000),
            Enrollment::issueToken(
                $this->enrollmentKey,
                array_replace($registration, ['apiKey' => str_repeat('cd', 32)]),
                $timestamp + 60000,
            ),
        ];
        foreach ($invalid as $enrollment) {
            self::check(
                $this->request('POST', '/api/servers/register', $encoded, enrollment: $enrollment)[0] === 403,
                'Invalid enrollment accepted.',
            );
            self::check($this->request('GET', '/api/servers/server-one')[0] === 404, 'Invalid enrollment wrote metadata.');
        }
        $token = Enrollment::issueToken($this->enrollmentKey, $registration, $timestamp + 60000);
        $this->secrets[] = $token;
        $hostile = "POST /api/servers/register HTTP/1.1\r\nHost: api.registry.baander.app\r\nContent-Type: application/json\r\n";
        self::check(
            $this->raw($hostile . "X-Baander-Enrollment: {$token}\r\nX-Baander-Enrollment: {$token}\r\nContent-Length: " . strlen($encoded) . "\r\n\r\n" . $encoded)[0] === 403,
            'Duplicate enrollment accepted.',
        );
        self::check($this->request('GET', '/api/servers/server-one')[0] === 404, 'Duplicate enrollment wrote metadata.');
        [$status, $body] = $this->request('POST', '/api/servers/register', $encoded, enrollment: $token);
        self::check($status === 200 && $body['data']['revision'] === 1, 'Initial registration failed.');
        [$status, $body] = $this->request('POST', '/api/servers/register', $encoded, enrollment: false);
        self::check($status === 200 && $body['data']['revision'] === 2, 'Owner heartbeat failed.');
        [$status, $body] = $this->request('GET', '/api/servers/server-one');
        self::check($status === 200 && $body['data']['url'] === $registration['url'], 'Lookup failed.');
        $before = $body['data'];
        $update = array_replace(
            $owned,
            ['url' => 'https://updated.baander.app', 'name' => 'Updated owner metadata', 'version' => '2.0.0'],
        );
        [$status, $receipt] = $this->request('POST', '/api/servers/register', self::json($update), enrollment: false);
        self::check(
            $status === 200 && $receipt['data']['revision'] === $before['revision'] + 1,
            'Owner update revision failed.',
        );
        [$status, $updated] = $this->request('GET', '/api/servers/server-one');
        self::check(
            $status === 200 && $updated['data']['revision'] === $receipt['data']['revision'],
            'Owner update lookup failed.',
        );
        foreach (['url', 'name', 'version'] as $field) {
            self::check($updated['data'][$field] === $update[$field], 'Owner metadata update failed: ' . $field);
        }
        [$status, $replayed] = $this->request('POST', '/api/servers/register', $encoded, enrollment: $token);
        self::check(
            $status === 200 && $replayed['data']['revision'] === $updated['data']['revision'] + 1,
            'Owner replay revision failed.',
        );
        [$status, $body] = $this->request('GET', '/api/servers/server-one');
        self::check(
            $status === 200 && $body['data']['revision'] === $replayed['data']['revision'],
            'Owner replay lookup failed.',
        );
        foreach (['url', 'name', 'version'] as $field) {
            self::check($body['data'][$field] === $owned[$field], 'Owner replay must retain last-write-wins metadata.');
        }
        $registration = array_replace(
            $registration,
            [
                'apiKey' => str_repeat('cd', 32),
                'url' => 'https://attacker.baander.app',
                'name' => 'Hostile replacement',
                'version' => '99.0.0',
            ],
        );
        $snapshot = $body['data'];
        self::check(
            $this->request('POST', '/api/servers/register', self::json($registration))[0] === 403,
            'Hostile credential replaced owner.',
        );
        self::check(
            $this->request('GET', '/api/servers/server-one')[1]['data'] === $snapshot,
            'Hostile update changed metadata.',
        );
        foreach ([
            ['GET', '/api/servers/missing', null, 404],
            ['POST', '/api/servers/register', '{', 400],
            ['POST', '/api/servers/register', '{}', 422],
            ['POST', '/api/servers/register', str_repeat('[', 9) . '0' . str_repeat(']', 9), 400],
            ['POST', '/api/servers/register', str_repeat(' ', 8193), 413],
        ] as [$method, $target, $payload, $expected]) {
            self::check(
                $this->request($method, $target, $payload)[0] === $expected,
                'Public route validation failed: ' . $expected,
            );
        }
        $this->malformed($encoded, $owned, $snapshot, $hostile);
        $this->concurrencyAndLimits($registration, $owned);
        $this->databaseAndLifecycle($registration, $owned, $encoded, $api, $db);
        $this->noSecrets(file_get_contents($d . '/api.log'));
        $this->noSecrets(file_get_contents($d . '/rqlite.log'));
        echo "PASS: native TLS API enrollment, owner replay, concurrent ownership, malformed JSON/wire types, "
            . "unchanged snapshots, secret exclusion, bounds, rate/pool limits, uncertain commits, schema recovery, "
            . "outage, deadlines and graceful/forced shutdown.\n";
    }

    /**
     * @param array<string,mixed> $owned
     * @param array<string,mixed> $snapshot
     */
    private function malformed(string $encoded, array $owned, array $snapshot, string $hostile): void
    {
        $cases = [
            ['invalid UTF-8', "{\"name\":\"\xff\"}", 400],
            ['trailing NUL', $encoded . "\x00", 400],
            ['embedded NUL', str_replace('Baander', "Baan\x00der", $encoded), 400],
            ['trailing JSON value', $encoded . ' {}', 400],
            ['scalar', 'true', 422],
            ['array', '[]', 422],
        ];
        foreach (['publicId' => 1, 'url' => [], 'name' => null, 'version' => false, 'apiKey' => (object) []] as $field => $value) {
            $cases[] = ['wrong ' . $field . ' type', self::json(array_replace($owned, [$field => $value])), 422];
        }
        foreach ($cases as [$label, $payload, $expected]) {
            self::check(
                $this->raw($hostile . 'Content-Length: ' . strlen($payload) . "\r\n\r\n" . $payload)[0] === $expected,
                $label,
            );
            self::check(
                $this->request('GET', '/api/servers/server-one')[1]['data'] === $snapshot,
                $label . ' changed metadata.',
            );
            self::check($this->request('GET', '/health')[0] === 200, $label . ' broke liveness.');
        }
        foreach (['/db/query', '/status'] as $target) {
            self::check($this->request('GET', $target)[0] === 404, 'Database route exposed: ' . $target);
        }
        foreach (['/db/execute', '/db/request', '/db/load', '/db/backup', '/join'] as $target) {
            self::check($this->request('POST', $target, '[]')[0] === 404, 'Database route exposed: ' . $target);
        }
        foreach ([
            $hostile . "Content-Length: 0\r\nContent-Length: 9\r\n\r\n",
            $hostile . "Content-Length: 0\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n",
            "GET /health HTTP/1.1\r\nHost: " . str_repeat('a', 8192) . "\r\n\r\n",
        ] as $wire) {
            [$status, $error] = $this->raw($wire);
            self::check(
                $status === 400 && $error === ['error' => 'Invalid or oversized HTTP request.'],
                'Malformed HTTP accepted.',
            );
        }
        $chunk = str_repeat(' ', 8193);
        [$status, $error] = $this->raw($hostile . "Transfer-Encoding: chunked\r\n\r\n" . dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n0\r\n\r\n");
        self::check(
            $status === 413 && $error === ['error' => 'Invalid or oversized HTTP request.'],
            'Oversized chunk accepted.',
        );
        $claim = array_replace($owned, ['publicId' => 'pipelined-claim']);
        $body = self::json($claim);
        $token = Enrollment::issueToken($this->enrollmentKey, $claim, (int) (microtime(true) * 1000) + 60000);
        $this->secrets[] = $token;
        [$status, $body] = $this->raw(
            "GET /health HTTP/1.1\r\nHost: api.registry.baander.app\r\n\r\n"
                . $hostile
                . "X-Baander-Enrollment: {$token}\r\nContent-Length: "
                . strlen($body)
                . "\r\n\r\n"
                . $body,
        );
        self::check($status === 200 && $body === ['data' => ['alive' => true]], 'Pipelined health response failed.');
        self::check($this->request('GET', '/api/servers/pipelined-claim')[0] === 404, 'Pipelined claim executed.');
        self::check(
            $this->request('GET', '/api/servers/server-one')[1]['data'] === $snapshot,
            'Pipeline changed owner metadata.',
        );
        self::check($this->request('GET', '/health')[0] === 200, 'Pipeline broke liveness.');
    }

    /**
     * @param array<string,mixed> $owned
     * @param array<string,mixed> $registration
     */
    private function concurrencyAndLimits(array $registration, array $owned): void
    {
        $a = array_replace(
            $registration,
            ['publicId' => 'concurrent-owner', 'name' => 'Owner A', 'apiKey' => str_repeat('ef', 32)],
        );
        $b = array_replace(
            $registration,
            ['publicId' => 'concurrent-owner', 'name' => 'Owner B', 'apiKey' => str_repeat('12', 32)],
        );
        $pendingA = $this->async(self::json($a));
        $pendingB = $this->async(self::json($b));
        $claims = [$this->await($pendingA), $this->await($pendingB)];
        $statuses = [$claims[0][0], $claims[1][0]];
        sort($statuses);
        self::check($statuses === [200, 403], 'Concurrent claim did not select exactly one owner.');
        $winner = $claims[0][0] === 200 ? $a : $b;
        self::check(
            $this->request('GET', '/api/servers/concurrent-owner')[1]['data']['name'] === $winner['name'],
            'Concurrent owner metadata mismatch.',
        );
        self::check(
            $this->request('POST', '/api/servers/register', self::json($winner))[1]['data']['revision'] === 2,
            'Winning owner cannot heartbeat.',
        );
        $pending = $this->socket($this->port);
        fwrite($pending, "GET /health HTTP/1.1\r\nHost: api.registry.baander.app\r\n");
        $start = microtime(true);
        self::check(fread($pending, 1) === '' && feof($pending), 'Partial request retained a session.');
        self::check(microtime(true) - $start < 2.5, 'Partial request deadline exceeded.');
        fclose($pending);
        self::check($this->request('GET', '/health')[0] === 200, 'Partial request broke liveness.');
        $config = $this->config;
        $port = Runtime::port();
        $config['api'] = array_replace($config['api'], ['port' => $port, 'requestsPerSecond' => 1, 'burst' => 1]);
        $limited = $this->launch($config, 'limited');
        $this->ready($port);
        $statuses = [];
        for ($number = 2; $number < 7; ++$number) {
            [$status, , $headers] = $this->request('GET', '/api/servers/server-one', port: $port, source: '127.0.0.' . $number);
            $statuses[] = $status;
            if ($status === 429) {
                self::check(($headers['retry-after'] ?? null) === '1', 'Rate limit missing Retry-After.');
            }
        }
        $denied = $statuses
            |> (static fn(array $values): array => array_filter($values, static fn(int $status): bool => $status === 429))
            |> count(...);
        self::check(
            $denied >= 4,
            'Rate bucket was not global across source addresses.',
        );
        $limited->signal(SIGTERM);
        self::check($limited->wait(3) === 0, 'Rate-limited process did not stop cleanly.');
    }

    /**
     * @param array<string,mixed> $owned
     * @param array<string,mixed> $registration
     */
    private function databaseAndLifecycle(array $registration, array $owned, string $encoded, Process $api, Process $db): void
    {
        $uncertain = array_replace(
            $owned,
            [
                'publicId' => 'lost-response-committed',
                'apiKey' => str_repeat('ef', 32),
                'url' => 'https://uncertain.baander.app',
                'name' => 'Committed without acknowledgement',
            ],
        );
        $digest = hash('sha256', $uncertain['apiKey']);
        $query = [
            'SELECT public_id, credential_digest, url, name, version, created_ms, updated_ms, last_seen_ms, revision FROM registries WHERE public_id=?',
            $uncertain['publicId'],
        ];
        $this->change(['armed' => true]);
        try {
            [$status, $body, $headers] = $this->request('POST', '/api/servers/register', self::json($uncertain));
            $state = $this->waitState(static fn(array $state): bool => $state['completed']);
            self::check($state['error'] === null, 'Lost-response commit oracle failed: ' . ($state['error'] ?? ''));
            self::check($status === 503 && ($headers['retry-after'] ?? null) === '1', 'Uncertain write was acknowledged.');
            $public = self::json([$body, $headers]);
            foreach ([$uncertain['apiKey'], $digest, 'credential_digest', 'apiKey'] as $secret) {
                self::check(!str_contains($public, $secret), 'Uncertain write exposed credential metadata.');
            }
            $row = $this->database([$query], true)['results'][0]['rows'][0];
            self::check(
                $row['credential_digest'] === $digest && $row['revision'] === 1 && $row['url'] === $uncertain['url'],
                'Lost acknowledgement did not follow a committed write.',
            );
            self::check($this->request('GET', '/ready')[0] === 200, 'Lost response broke readiness.');
            [$status, $lookup] = $this->request('GET', '/api/servers/' . $uncertain['publicId']);
            self::check(
                $status === 200 && $lookup['data']['revision'] === 1 && $lookup['data']['url'] === $uncertain['url'],
                'Committed uncertain registration unavailable.',
            );
            self::check(!str_contains(self::json($lookup), $digest), 'Lookup exposed uncertain digest.');
            self::check(HttpProxy::state($this->statePath)['writes'] === 1, 'Registry replayed an uncertain write.');
            [$status, $body] = $this->request('POST', '/api/servers/register', self::json($uncertain), enrollment: false);
            self::check($status === 200 && $body['data']['revision'] === 2, 'Explicit owner retry failed.');
            $retried = $this->database([$query], true)['results'][0]['rows'][0];
            self::check(
                $retried['credential_digest'] === $digest && $retried['revision'] === 2,
                'Owner retry changed ownership.',
            );
            $hostile = array_replace(
                $uncertain,
                [
                    'apiKey' => str_repeat('12', 32),
                    'url' => 'https://hostile-uncertain.baander.app',
                    'name' => 'Hostile claim',
                ],
            );
            self::check(
                $this->request('POST', '/api/servers/register', self::json($hostile))[0] === 403,
                'Hostile uncertain claim accepted.',
            );
            self::check(
                $this->database([$query], true)['results'][0]['rows'][0] === $retried,
                'Hostile uncertain claim changed row.',
            );
            self::check($this->request('GET', '/ready')[0] === 200, 'Hostile uncertain claim broke readiness.');
            self::check(
                $this->request('GET', '/api/servers/' . $uncertain['publicId'])[1]['data']['revision'] === 2,
                'Hostile uncertain claim changed revision.',
            );
            self::check(HttpProxy::state($this->statePath)['writes'] === 3, 'Uncertain write attempt count mismatch.');
        } finally {
            $this->change(['armed' => false]);
        }
        $ownedQuery = [
            'SELECT public_id, credential_digest, url, name, version, created_ms, updated_ms, last_seen_ms, revision FROM registries WHERE public_id=?',
            'server-one',
        ];
        $before = $this->database([$ownedQuery], true)['results'][0]['rows'][0];
        self::check(
            $this->request('POST', '/api/servers/register', self::json($registration))[0] === 403,
            'Hostile claim accepted.',
        );
        self::check(
            $this->database([$ownedQuery], true)['results'][0]['rows'][0] === $before,
            'Rejected claim changed stored row.',
        );
        $this->database([['UPDATE registries SET last_seen_ms=? WHERE public_id=?', 1, 'server-one']]);
        self::check($this->request('GET', '/api/servers/server-one')[0] === 404, 'Offline metadata exposed.');
        self::check(
            $this->request('POST', '/api/servers/register', self::json($registration))[0] === 403,
            'Offline ownership replaced.',
        );
        self::check(
            $this->request('POST', '/api/servers/register', self::json($owned))[0] === 200,
            'Offline owner heartbeat rejected.',
        );
        self::check(
            $this->request('GET', '/api/servers/server-one')[1]['data']['url'] === $owned['url'],
            'Revived metadata mismatch.',
        );
        $this->change(['malformedReadiness' => true]);
        self::check($this->request('GET', '/ready')[0] === 503, 'Malformed readiness accepted.');
        self::check($this->request('GET', '/api/servers/server-one')[0] === 503, 'Malformed schema allowed lookup.');
        $this->change(['malformedReadiness' => false]);
        self::check($this->request('GET', '/ready')[0] === 200, 'Malformed schema did not recover.');
        $checksum = $this->database(['SELECT checksum FROM schema_migrations'], true)['results'][0]['rows'][0]['checksum'];
        $this->database([['UPDATE schema_migrations SET checksum=?', 'incompatible']]);
        foreach ([
            ['GET', '/ready', null],
            ['GET', '/api/servers/server-one', null],
            ['POST', '/api/servers/register', $encoded],
        ] as [$method, $target, $body]) {
            self::check($this->request($method, $target, $body)[0] === 503, 'Incompatible schema accepted traffic.');
        }
        $this->database([['UPDATE schema_migrations SET checksum=?', $checksum]]);
        self::check(
            $this->request('GET', '/ready')[0] === 200 && $this->request('GET', '/api/servers/server-one')[0] === 200,
            'Schema recovery failed.',
        );
        [$port, $process] = $this->graceful(3000, 'default-budget', null, null);
        $this->change(['delay' => 2.5, 'arrivals' => 0]);
        $result = $this->request(
            'POST',
            '/api/servers/register',
            self::json(array_replace($registration, ['publicId' => 'default-timeout-uncertain'])),
            $port,
        );
        self::check(HttpProxy::state($this->statePath)['arrivals'] > 0, 'Default timeout never reached database.');
        self::check(
            $result[0] === 503 && ($result[2]['retry-after'] ?? null) === '1',
            'Default timeout disconnected or did not return 503.',
        );
        $this->change(['delay' => 0]);
        $process->signal(SIGTERM);
        self::check($process->wait(3) === 0, 'Default-budget instance did not stop.');
        $this->change(['delay' => 0.25, 'arrivals' => 0]);
        $writes = [];
        for ($number = 0; $number < 2; ++$number) {
            $writes[] = $this->async(self::json(array_replace($registration, ['publicId' => 'capacity-' . $number])));
        }
        $this->waitState(static fn(array $state): bool => $state['arrivals'] >= 2);
        $result = $this->request('GET', '/api/servers/server-one');
        self::check($result[0] === 503 && ($result[2]['retry-after'] ?? null) === '1', 'Saturated pool accepted lookup.');
        foreach ($writes as $write) {
            self::check($this->await($write)[0] === 200, 'In-flight capacity write failed.');
        }
        $this->change(['delay' => 0, 'arrivals' => 0]);
        self::check($this->request('GET', '/api/servers/server-one')[0] === 200, 'Pool did not recover.');
        [$port, $process] = $this->graceful(500, 'deadline', 100);
        $this->change(['delay' => 0.3, 'arrivals' => 0]);
        $start = microtime(true);
        $result = $this->request(
            'POST',
            '/api/servers/register',
            self::json(array_replace($registration, ['publicId' => 'expired-unacknowledged'])),
            $port,
        );
        self::check(
            $result[0] === 503 && ($result[2]['retry-after'] ?? null) === '1',
            'Overall deadline acknowledged pending write.',
        );
        self::check(HttpProxy::state($this->statePath)['arrivals'] > 0, 'Deadline never reached database.');
        self::check(microtime(true) - $start < 0.8, 'Overall request deadline exceeded.');
        $this->change(['delay' => 0]);
        self::check($this->request('GET', '/ready', port: $port)[0] === 200, 'Deadline instance did not recover.');
        $process->signal(SIGTERM);
        self::check($process->wait(2) === 0, 'Deadline instance did not stop.');
        [$port, $process] = $this->graceful(1000, 'drain');
        $this->change(['delay' => 0.25, 'arrivals' => 0]);
        $write = $this->async(self::json(array_replace($registration, ['publicId' => 'drained-commit'])), $port);
        $this->waitState(static fn(array $state): bool => $state['arrivals'] > 0);
        $process->signal(SIGTERM);
        self::check($this->await($write)[0] === 200, 'Graceful drain lost committed response.');
        self::check($process->wait(3) === 0, 'Graceful drain did not stop.');
        $this->change(['delay' => 0]);
        self::check($this->request('GET', '/api/servers/drained-commit')[0] === 200, 'Drained commit unavailable.');
        [$port, $process] = $this->graceful(50, 'forced-drain');
        $this->change(['delay' => 0.8, 'arrivals' => 0]);
        $write = $this->async(self::json(array_replace($registration, ['publicId' => 'forced-unacknowledged'])), $port);
        $this->waitState(static fn(array $state): bool => $state['arrivals'] > 0);
        $start = microtime(true);
        $process->signal(SIGTERM);
        self::check($process->wait(2) === 0 && microtime(true) - $start < 1, 'Forced shutdown exceeded grace.');
        $acknowledged = false;
        try {
            $acknowledged = $this->await($write, 2)[0] === 200;
        } catch (\RuntimeException) {
            // Cutoff can close an unfinished response before HTTP framing exists.
        }
        self::check(!$acknowledged, 'Cutoff acknowledged an unfinished commit.');
        $this->change(['delay' => 0]);
        $db->signal(SIGTERM);
        $db->wait(5);
        [$status, $body, $headers] = $this->request('GET', '/api/servers/server-one');
        self::check(
            $status === 503 && ($headers['retry-after'] ?? null) === '1' && !isset($body['url']),
            'Database outage returned stale metadata.',
        );
        self::check(
            $this->request('GET', '/ready')[0] === 503 && $this->request('GET', '/health')[0] === 200,
            'Outage readiness/liveness mismatch.',
        );
        $pending = $this->socket($this->port);
        fwrite($pending, "GET /health HTTP/1.1\r\n");
        $start = microtime(true);
        $api->signal(SIGTERM);
        self::check($api->wait(3) === 0 && microtime(true) - $start < 3, 'API shutdown exceeded bound.');
        stream_set_timeout($pending, 1);
        self::check(fread($pending, 1) === '' && feof($pending), 'Shutdown retained incomplete session.');
        fclose($pending);
    }
}
