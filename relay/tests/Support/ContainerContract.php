<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Baander\RegistryTests;

use Baander\Registry\Qualification\DockerOwnership;
use RuntimeException;
use Throwable;

final class ContainerContract
{
    private string $prefix;
    private string $directory;
    /**
     * @var list<string>
     */
    private array $containers = [];
    /**
     * @var list<string>
     */
    private array $dataVolumes = [];
    /**
     * @var list<string>
     */
    private array $secretVolumes = [];
    private DockerOwnership $ownership;
    private float $cleanupSeconds = 120;
    private string $password = 'disposable-container-secret';
    private string $credential;
    private string $apiImage;
    private string $rqliteImage;
    private string $apiVolume;
    private string $enrollmentKey;
    public static function main(): void
    {
        $options = Runtime::arguments(['api-image', 'rqlite-image'], [
            'guards-only' => false,
            'target-file' => getenv('BAANDER_TOOLING_TARGET_FILE') ?: null,
            'ownership-receipt' => getenv('BAANDER_REGISTRY_OWNERSHIP_RECEIPT') ?: null,
        ]);
        Runtime::requireThat(is_string($options['target-file']) && is_string($options['ownership-receipt']), 'Container qualification requires a captured target file and an ownership receipt path.');
        $self = new self();
        $environment = getenv();
        foreach (['DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH'] as $name) {
            unset($environment[$name]);
        }
        $budget = getenv('BAANDER_CLEANUP_TIMEOUT') ?: '120';
        Runtime::requireThat(is_numeric($budget) && is_finite((float) $budget) && (float) $budget > 0, 'Container cleanup needs a positive finite budget.');
        $self->cleanupSeconds = (float) $budget;
        $self->ownership = new DockerOwnership(
            $options['ownership-receipt'],
            DockerOwnership::read($options['target-file']),
            static fn(array $argv, ?string $input, float $timeout): array => Runtime::run($argv, $input, $timeout, $environment),
        );
        $self->apiImage = $options['api-image'];
        $self->rqliteImage = $options['rqlite-image'];
        $self->prefix = 'baander-registry-test-' . bin2hex(random_bytes(6));
        $self->credential = str_repeat('bc', 32);
        $self->directory = Runtime::temporaryDirectory($self->prefix);
        chmod($self->directory, 0755);
        $primary = null;
        try {
            $self->qualify($options['guards-only']);
        } catch (Throwable $error) {
            $primary = $error;
            foreach ($self->containers as $name) {
                try {
                    $logs = $self->docker(['logs', '--tail', '80', $name], false);
                    file_put_contents(
                        sys_get_temp_dir() . '/' . $name . '.log',
                        str_replace([$self->password, $self->credential], '[REDACTED]', $logs['stdout'] . $logs['stderr']),
                    );
                } catch (Throwable $diagnostic) {
                    fwrite(STDERR, 'Cannot retain container diagnostics: ' . $diagnostic->getMessage() . "\n");
                }
            }
        } finally {
            $self->finish($primary);
        }
    }

    private function finish(?Throwable $primary): void
    {
        // The owning gate's cancellation does not cancel its bounded cleanup.
        \Swoole\Process::signal(2, static function (): void {});
        \Swoole\Process::signal(15, static function (): void {});
        $failures = [];
        try {
            $failures = $this->ownership->cleanup($this->cleanupSeconds);
            try {
                Runtime::removeTree($this->directory);
            } catch (Throwable $cleanup) {
                $failures[] = $cleanup->getMessage();
            }
        } finally {
            \Swoole\Process::signal(2, static fn() => throw new Interrupted(2));
            \Swoole\Process::signal(15, static fn() => throw new Interrupted(15));
        }
        if ($failures !== []) {
            $status = $primary instanceof Interrupted ? 128 + $primary->signal : ($primary?->getCode() ?: 1);
            throw new RuntimeException(
                ($primary !== null ? $primary->getMessage() . '; ' : '') . 'Relay resource cleanup failed: ' . implode('; ', $failures)
                    . '. Retry receipt: ' . $this->ownership->receipt,
                $status > 0 && $status <= 255 ? $status : 1,
                $primary,
            );
        }
        if ($primary !== null) {
            throw $primary;
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function docker(array $arguments, bool $check = true, ?string $input = null): array
    {
        $result = $this->ownership->run($arguments, $input, 180);
        if ($check && $result['code'] !== 0) {
            throw new RuntimeException(
                'Disposable Docker command failed: ' . str_replace([$this->password, $this->credential], '[REDACTED]', $result['stderr']),
                $result['code'],
            );
        }
        return $result;
    }
    private function issue(string $name, ?string $dns = null): void
    {
        $path = $this->directory . '/' . $name;
        $result = Runtime::run([
            'openssl',
            'req',
            '-new',
            '-newkey',
            'rsa:2048',
            '-nodes',
            '-keyout',
            $path . '.key',
            '-out',
            $path . '.csr',
            '-subj',
            '/CN=' . $name,
        ]);
        Runtime::requireThat($result['code'] === 0, 'Certificate request failed');
        file_put_contents(
            $path . '.ext',
            "extendedKeyUsage=serverAuth,clientAuth\n" . ($dns === null ? '' : "subjectAltName={$dns}\n"),
        );
        $result = Runtime::run([
            'openssl',
            'x509',
            '-req',
            '-in',
            $path . '.csr',
            '-CA',
            $this->directory . '/ca.crt',
            '-CAkey',
            $this->directory . '/ca.key',
            '-CAcreateserial',
            '-out',
            $path . '.crt',
            '-days',
            '1',
            '-extfile',
            $path . '.ext',
        ]);
        Runtime::requireThat($result['code'] === 0, 'Certificate signing failed');
    }
    private function copyFixture(string $source, string $suffix): string
    {
        $volume = $this->prefix . '-fixture-' . $suffix;
        $helper = $this->prefix . '-copy-' . $suffix;
        $this->docker(['volume', 'create', $volume]);
        $this->containers[] = $helper;
        $this->docker([
            'create',
            '--name',
            $helper,
            '-v',
            "{$volume}:/fixture",
            '--entrypoint',
            '/bin/true',
            $this->rqliteImage,
        ]);
        $this->docker(['cp', $source . '/.', $helper . ':/fixture']);
        $this->docker(['rm', $helper]);
        $this->forgetContainer($helper);
        return $volume;
    }
    private function forgetContainer(string $name): void
    {
        $this->containers = array_values(array_filter($this->containers, static fn(string $item): bool => $item !== $name));
    }
    /**
     * @param array<string,string> $extra
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function start(int $index, string $mode, array $extra = [], bool $check = true, bool $detached = true): array
    {
        $name = $this->prefix . '-node-' . $index;
        if ($detached) {
            $this->containers[] = $name;
        }
        $command = $detached ? ['run', '--name', $name, '-d'] : ['run', '--rm'];
        $command = [
            ...$command,
            '--network',
            $this->prefix,
            '--network-alias',
            "node-{$index}.rqlite.baander.app",
            '--read-only',
            '--memory',
            '352m',
            '--cpus',
            '0.75',
            '--tmpfs',
            '/tmp:rw,noexec,nosuid,nodev,size=32m',
            '--cap-drop',
            'ALL',
            '--security-opt',
            'no-new-privileges',
            '-v',
            $this->dataVolumes[$index] . ':/var/lib/rqlite',
            '-v',
            $this->secretVolumes[$index] . ':/run/secrets/rqlite:ro',
        ];
        $values = array_replace(
            [
                'RQLITE_NODE_ID' => "node-{$index}",
                'RQLITE_PRIVATE_BIND_IP' => '10.42.0.11',
                'RQLITE_HTTP_ADVERTISE' => "node-{$index}.rqlite.baander.app:4001",
                'RQLITE_RAFT_ADVERTISE' => "node-{$index}.rqlite.baander.app:4002",
                'RQLITE_JOIN_ADDRESSES' => implode(',', array_map(static fn(int $i): string => "node-{$i}.rqlite.baander.app:4002", range(0, 4))),
                'RQLITE_START_MODE' => $mode,
            ],
            $extra,
        );
        foreach ($values as $key => $value) {
            array_push($command, '-e', "{$key}={$value}");
        }
        return $this->docker([...$command, $this->rqliteImage], $check);
    }
    private function waitFor(callable $condition, float $seconds, string $failure): void
    {
        $until = microtime(true) + $seconds;
        do {
            if ($condition()) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $until);
        throw new RuntimeException($failure);
    }
    /**
     * @return list<string>
     */
    private function queryCommand(string $configuration): array
    {
        return [
            'run',
            '--rm',
            '--network',
            $this->prefix,
            '-v',
            $this->apiVolume . ':/fixture:ro',
            '--entrypoint',
            'curl',
            $this->apiImage,
            '--silent',
            '--show-error',
            '--fail',
            '--max-time',
            '5',
            '--config',
            '/fixture/' . $configuration,
            '--cacert',
            '/fixture/ca.crt',
            '--cert',
            '/fixture/client.crt',
            '--key',
            '/fixture/client.key',
        ];
    }
    /**
     * @param array<string,mixed>|null $body
     * @return array{int,array<string,mixed>}
     */
    private function request(string $method, string $target, ?array $body = null): array
    {
        $command = [
            'run',
            '--rm',
            '-i',
            '--network',
            $this->prefix,
            '-v',
            $this->apiVolume . ':/fixture:ro',
            '--entrypoint',
            'curl',
            $this->apiImage,
            '--silent',
            '--show-error',
            '--fail-with-body',
            '--max-time',
            '3',
            '--cacert',
            '/fixture/ca.crt',
            '--request',
            $method,
            '--write-out',
            "\n%{http_code}",
        ];
        if ($body !== null) {
            array_push($command, '--header', 'Content-Type: application/json', '--data-binary', '@-');
            if ($method === 'POST' && $target === '/api/servers/register') {
                array_push(
                    $command,
                    '--header',
                    'X-Baander-Enrollment: ' . Enrollment::issueToken($this->enrollmentKey, $body, (int) floor(microtime(true) * 1000) + 60000),
                );
            }
        }
        $command[] = 'https://api.registry.baander.app:9502' . $target;
        $response = $this->docker($command, false, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        if (!in_array($response['code'], [0, 22], true)) {
            throw new RuntimeException('Disposable TLS probe could not reach the API');
        }
        $separator = strrpos($response['stdout'], "\n");
        Runtime::requireThat($separator !== false, 'Missing probe status');
        return [
            (int) substr($response['stdout'], $separator + 1),
            json_decode(substr($response['stdout'], 0, $separator), true, flags: JSON_THROW_ON_ERROR),
        ];
    }
    private function ready(): void
    {
        $this->waitFor(
            function (): bool {
                try {
                    return $this->request('GET', '/ready')[0] === 200;
                } catch (\RuntimeException|\JsonException) {
                    return false;
                }
            },
            45,
            'Five-voter container cluster never became ready',
        );
    }
    private function qualify(bool $guardsOnly): void
    {
        Tls::authority($this->directory);
        $this->issue('api', 'DNS:api.registry.baander.app');
        $this->issue('client');
        $auth = [
            ['username' => 'registry', 'password' => $this->password, 'perms' => ['query', 'execute']],
            ['username' => 'registry-cluster', 'password' => $this->password, 'perms' => ['join']],
            ['username' => 'registry-observer', 'password' => $this->password, 'perms' => ['status']],
        ];
        file_put_contents($this->directory . '/password', $this->password . "\n");
        file_put_contents($this->directory . '/database-curl.conf', 'user = "registry:' . $this->password . '"' . "\n");
        file_put_contents($this->directory . '/status-curl.conf', 'user = "registry-observer:' . $this->password . '"' . "\n");
        file_put_contents($this->directory . '/auth.json', json_encode($auth, JSON_THROW_ON_ERROR));
        $secrets = [];
        for ($index = 0; $index < 5; ++$index) {
            $this->issue("node-{$index}", "DNS:raft.registry.baander.app,DNS:node-{$index}.rqlite.baander.app");
            $target = $this->directory . '/secrets-' . $index;
            mkdir($target, 0755);
            foreach ([
                'ca.crt' => 'ca.crt',
                "node-{$index}.crt" => 'node.crt',
                "node-{$index}.key" => 'node.key',
                'auth.json' => 'auth.json',
            ] as $source => $destination) {
                copy($this->directory . '/' . $source, $target . '/' . $destination);
                chmod($target . '/' . $destination, 0644);
            }
            $secrets[] = $target;
        }
        $this->docker(['network', 'create', $this->prefix]);
        foreach ($secrets as $index => $source) {
            $this->secretVolumes[] = $this->copyFixture($source, (string) $index);
        }
        for ($index = 0; $index < 5; ++$index) {
            $volume = $this->prefix . "-data-{$index}";
            $this->docker(['volume', 'create', $volume]);
            $this->dataVolumes[] = $volume;
        }
        foreach ([
            ['restart', [], 'restart requires existing voter state'],
            ['bootstrap', ['RQLITE_EXPECTED_VOTERS' => '3'], 'requires five initial voters'],
            ['join-new', ['RQLITE_JOIN_ADDRESSES' => ''], 'requires explicit peer inventory'],
            [
                'join-new',
                ['RQLITE_JOIN_ADDRESSES' => 'node-0.rqlite.baander.app:4002'],
                'peer other than this node',
            ],
            ['bootstrap', ['RQLITE_PRIVATE_BIND_IP' => '0.0.0.0'], 'RFC1918 private interface'],
        ] as [$mode, $changes, $expected]) {
            $result = $this->start(0, $mode, $changes, false, false);
            Runtime::requireThat($result['code'] === 64 && str_contains($result['stderr'], $expected), $result['stderr']);
            Runtime::requireThat(!str_contains($result['stderr'], $this->password), 'Guard leaked password');
        }
        echo "PASS: empty restart, reduced voters, missing join peers and public DB binding rejected\n";
        $this->start(0, 'bootstrap');
        $node = $this->prefix . '-node-0';
        $this->waitFor(
            fn(): bool => $this->docker(['exec', $node, 'test', '-s', '/var/lib/rqlite/raft.db'], false)['code'] === 0,
            10,
            'Incomplete bootstrap never created native Raft storage',
        );
        $this->docker(['stop', '--time', '15', $node]);
        $this->docker(['rm', $node]);
        $this->forgetContainer($node);
        $this->start(0, 'restart');
        $this->waitFor(
            function () use ($node): bool {
                $logs = $this->docker(['logs', $node]);
                Runtime::requireThat(
                    !str_contains($logs['stdout'] . $logs['stderr'], 'bootstrapping single new node'),
                    'Incomplete five-voter bootstrap restarted as a competing single-voter cluster',
                );
                return trim($this->docker(['inspect', '--format', '{{.State.Running}}', $node])['stdout']) === 'false';
            },
            5,
            'Incomplete bootstrap restart did not fail closed',
        );
        Runtime::requireThat(
            trim($this->docker(['inspect', '--format', '{{.State.ExitCode}}', $node])['stdout']) !== '0',
            'Incomplete bootstrap exited successfully',
        );
        $this->docker(['rm', $node]);
        $this->forgetContainer($node);
        $volume = $this->prefix . '-fresh-data-0';
        $this->docker(['volume', 'create', $volume]);
        $this->dataVolumes[0] = $volume;
        echo "PASS: incomplete five-voter bootstrap cannot restart as a new single-voter cluster\n";
        if ($guardsOnly) {
            return;
        }
        for ($index = 0; $index < 5; ++$index) {
            $this->start($index, 'bootstrap');
        }
        $this->enrollmentKey = random_bytes(32);
        file_put_contents($this->directory . '/enrollment.key', $this->enrollmentKey);
        $config = [
            'api' => [
                'address' => '0.0.0.0',
                'port' => 9502,
                'certificate' => '/fixture/api.crt',
                'key' => '/fixture/api.key',
                'enrollmentKeyFile' => '/fixture/enrollment.key',
            ],
            'database' => [
                'endpoints' => array_map(static fn(int $i): array => ['url' => "https://node-{$i}.rqlite.baander.app:4001"], range(0, 4)),
                'ca' => '/fixture/ca.crt',
                'certificate' => '/fixture/client.crt',
                'key' => '/fixture/client.key',
                'username' => 'registry',
                'passwordFile' => '/fixture/password',
                'connections' => 2,
            ],
        ];
        file_put_contents($this->directory . '/config.json', json_encode($config, JSON_THROW_ON_ERROR));
        $apiDirectory = $this->directory . '/api-runtime';
        mkdir($apiDirectory, 0755);
        foreach ([
            'config.json',
            'api.crt',
            'api.key',
            'client.crt',
            'client.key',
            'ca.crt',
            'password',
            'enrollment.key',
            'database-curl.conf',
            'status-curl.conf',
        ] as $file) {
            copy($this->directory . '/' . $file, $apiDirectory . '/' . $file);
            chmod($apiDirectory . '/' . $file, 0644);
        }
        $apiName = $this->prefix . '-api';
        $this->apiVolume = $this->copyFixture($apiDirectory, 'api');
        $this->containers[] = $apiName;
        $this->docker([
            'run',
            '-d',
            '--name',
            $apiName,
            '--network',
            $this->prefix,
            '--network-alias',
            'api.registry.baander.app',
            '--read-only',
            '--memory',
            '96m',
            '--cpus',
            '0.25',
            '--cap-drop',
            'ALL',
            '--security-opt',
            'no-new-privileges',
            '-v',
            $this->apiVolume . ':/fixture:ro',
            $this->apiImage,
            '--config',
            '/fixture/config.json',
        ]);
        $this->ready();
        $value = [
            'publicId' => 'container-contract',
            'url' => 'https://container-contract.baander.app',
            'name' => 'container',
            'version' => '1',
            'apiKey' => $this->credential,
        ];
        [$status, $body] = $this->request('POST', '/api/servers/register', $value);
        Runtime::requireThat($status === 200, 'Registration failed');
        Runtime::requireThat(
            !array_key_exists('apiKey', $body['data']) && !array_key_exists('credential_digest', $body['data']),
            'Registration exposed credential',
        );
        $revision = $body['data']['revision'];
        Runtime::requireThat(
            $this->request('GET', '/api/servers/container-contract')[0] === 200,
            'Registered server lookup failed',
        );
        echo "PASS: five native TLS voters, nonroot read-only API registration and lookup\n";
        for ($index = 0; $index < 5; ++$index) {
            $name = $this->prefix . "-node-{$index}";
            $this->docker(['stop', '--time', '15', $name]);
            $this->docker(['rm', $name]);
            $this->forgetContainer($name);
            $rejected = $this->start($index, 'bootstrap', [], false, false);
            Runtime::requireThat(
                $rejected['code'] === 64 && str_contains($rejected['stderr'], 'empty data directory'),
                'Bootstrap accepted existing data',
            );
            if ($index === 0) {
                $rejected = $this->start($index, 'restart', ['RQLITE_NODE_ID' => 'different-node'], false, false);
                Runtime::requireThat(
                    $rejected['code'] === 64 && str_contains($rejected['stderr'], 'different voter identity'),
                    'Restart accepted changed voter identity',
                );
                $this->docker([
                    'run',
                    '--rm',
                    '-v',
                    $this->dataVolumes[$index] . ':/var/lib/rqlite',
                    '--entrypoint',
                    '/bin/sh',
                    $this->rqliteImage,
                    '-c',
                    "mkdir -p /var/lib/rqlite/raft && printf '{}' > /var/lib/rqlite/raft/peers.json",
                ]);
                $rejected = $this->start($index, 'restart', [], false, false);
                Runtime::requireThat(
                    $rejected['code'] === 64 && str_contains($rejected['stderr'], 'manual quorum recovery'),
                    'Restart accepted manual quorum recovery',
                );
                $this->docker([
                    'run',
                    '--rm',
                    '-v',
                    $this->dataVolumes[$index] . ':/var/lib/rqlite',
                    '--entrypoint',
                    '/bin/sh',
                    $this->rqliteImage,
                    '-c',
                    'rm /var/lib/rqlite/raft/peers.json',
                ]);
            }
            $this->start($index, 'restart');
        }
        $this->ready();
        [$status, $body] = $this->request('GET', '/api/servers/container-contract');
        Runtime::requireThat(
            $status === 200 && $body['data']['revision'] === $revision,
            'Rolling restart lost acknowledged revision',
        );
        $nodes = $this->docker([...$this->queryCommand('status-curl.conf'), 'https://node-0.rqlite.baander.app:4001/nodes']);
        $membership = json_decode($nodes['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $actual = array_keys($membership);
        sort($actual);
        Runtime::requireThat(
            $actual === array_map(static fn(int $i): string => "node-{$i}", range(0, 4)),
            'Unexpected cluster membership',
        );
        Runtime::requireThat(
            array_all($membership, static fn(array $node): bool => (bool) $node['voter']),
            'Cluster member is not a voter',
        );
        $replacementName = $this->prefix . '-node-4';
        $this->docker(['stop', '--time', '15', $replacementName]);
        $this->docker(['rm', $replacementName]);
        $this->forgetContainer($replacementName);
        $replacementVolume = $this->prefix . '-replacement-data-4';
        $this->docker(['volume', 'create', $replacementVolume]);
        $this->dataVolumes[4] = $replacementVolume;
        $this->start(4, 'join-new');
        $this->waitFor(
            function () use ($replacementName): bool {
                $logs = $this->docker(['logs', $replacementName]);
                return str_contains($logs['stdout'] . $logs['stderr'], 'successfully joined cluster');
            },
            30,
            'Explicit replacement did not join the existing cluster',
        );
        $command = $this->queryCommand('database-curl.conf');
        array_splice($command, 2, 0, ['-i']);
        $query = $this->docker(
            [
                ...$command,
                '--data-binary',
                '@-',
                'https://node-4.rqlite.baander.app:4001/db/query?level=linearizable&associative',
            ],
            input: json_encode(["SELECT revision FROM registries WHERE public_id='container-contract'"], JSON_THROW_ON_ERROR),
        );
        $rows = json_decode($query['stdout'], true, flags: JSON_THROW_ON_ERROR)['results'][0]['rows'];
        Runtime::requireThat($rows === [['revision' => $revision]], 'Replacement did not read acknowledged revision');
        echo "PASS: explicit empty replacement joins existing five-voter membership and reads acknowledged revision\n";
        foreach ($this->containers as $name) {
            $logs = $this->docker(['logs', $name]);
            Runtime::requireThat(!str_contains($logs['stdout'] . $logs['stderr'], $this->password), 'Container leaked password');
            Runtime::requireThat(
                !str_contains($logs['stdout'] . $logs['stderr'], $value['apiKey']),
                'Container leaked registration credential',
            );
            Runtime::requireThat(
                trim($this->docker(['inspect', '--format', '{{.Config.User}}', $name])['stdout']) === '10001:10001',
                'Container did not run under required user',
            );
        }
        $this->docker(['stop', '--time', '7', $apiName]);
        Runtime::requireThat(
            trim($this->docker(['inspect', '--format', '{{.State.ExitCode}}', $apiName])['stdout']) === '0',
            'API did not exit cleanly on SIGTERM',
        );
        echo "PASS: bootstrap reuse rejected, rolling restart retains acknowledged revision, API SIGTERM exits cleanly\n";
    }
}
