<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class Cluster
{
    public string $enrollmentKey;
    public string $enrollmentKeyFile;
    public string $clientKey;
    public string $clientCert;
    public string $apiKey;
    public string $apiCert;
    /**
     * @var array<string,mixed>
     */

    public array $tls;
    /**
     * @var array<string,mixed>
     */

    public array $apiTls;
    /**
     * @var list<int>
     */

    public array $httpPorts = [];
    /**
     * @var array<int,Process>
     */

    public array $processes = [];
    /**
     * @var list<string>
     */

    public array $logs = [];
    /**
     * @var array<int,resource>
     */

    private array $reservations = [];
    public FaultNetwork $network;
    public string $dataRoot;
    public function __construct(public string $directory, public string $binary, public int $count, ?string $dataRoot = null)
    {
        $this->dataRoot = $dataRoot ?? $directory;
        $this->enrollmentKey = random_bytes(32);
        $this->enrollmentKeyFile = "{$directory}/enrollment.key";
        file_put_contents($this->enrollmentKeyFile, $this->enrollmentKey);
        chmod($this->enrollmentKeyFile, 0600);
        try {
            Tls::authority($directory);
            [$this->clientKey, $this->clientCert] = Tls::certificate($directory, 'client');
            [$this->apiKey, $this->apiCert] = Tls::certificate($directory, 'api', 'api.registry.baander.app');
            for ($index = 0; $index < $count; ++$index) {
                $name = "node-{$index}";
                Runtime::checked([
                    'openssl',
                    'req',
                    '-new',
                    '-newkey',
                    'rsa:2048',
                    '-nodes',
                    '-keyout',
                    "{$directory}/{$name}.key",
                    '-out',
                    "{$directory}/{$name}.csr",
                    '-subj',
                    "/CN={$name}",
                ]);
                file_put_contents(
                    "{$directory}/{$name}.ext",
                    "subjectAltName=DNS:raft.registry.baander.app,DNS:node-{$index}.rqlite.baander.app\nextendedKeyUsage=serverAuth,clientAuth\n",
                );
                Runtime::checked([
                    'openssl',
                    'x509',
                    '-req',
                    '-in',
                    "{$directory}/{$name}.csr",
                    '-CA',
                    "{$directory}/ca.crt",
                    '-CAkey',
                    "{$directory}/ca.key",
                    '-CAcreateserial',
                    '-out',
                    "{$directory}/{$name}.crt",
                    '-days',
                    '1',
                    '-extfile',
                    "{$directory}/{$name}.ext",
                ]);
            }
            $ports = [];
            for ($i = 0; $i < $count * 2; ++$i) {
                $this->reservations[$i] = Runtime::reservePort();
                $ports[] = Runtime::socketPort($this->reservations[$i]);
            }
            $this->httpPorts = array_slice($ports, 0, $count);
            file_put_contents("{$directory}/password", "disposable-cluster-secret\n");
            file_put_contents(
                "{$directory}/auth.json",
                json_encode(
                    [['username' => 'registry', 'password' => 'disposable-cluster-secret', 'perms' => ['all']]],
                    JSON_THROW_ON_ERROR,
                ),
            );
            $this->tls = [
                'cafile' => "{$directory}/ca.crt",
                'local_cert' => $this->clientCert,
                'local_pk' => $this->clientKey,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ];
            $this->apiTls = ['cafile' => "{$directory}/ca.crt", 'verify_peer' => true, 'verify_peer_name' => true];
            $this->network = new FaultNetwork($directory, $count, array_slice($ports, $count));
        } catch (\Throwable $failure) {
            foreach ($this->reservations as $socket) {
                fclose($socket);
            }
            $this->reservations = [];
            throw $failure;
        }
    }
    public function dataDirectory(int $index): string
    {
        return $this->dataRoot . "/data-{$index}";
    }
    public function startNode(int $index): void
    {
        $directory = $this->directory;
        $command = [
            $this->binary,
            '-node-id',
            "node-{$index}",
            '-http-addr',
            '127.0.0.1:' . $this->httpPorts[$index],
            '-http-adv-addr',
            "node-{$index}.rqlite.baander.app:" . $this->httpPorts[$index],
            '-raft-addr',
            '127.0.0.1:' . $this->network->realPorts[$index],
            '-raft-adv-addr',
            '127.0.0.1:' . $this->network->ports[$index],
            '-http-cert',
            "{$directory}/node-{$index}.crt",
            '-http-key',
            "{$directory}/node-{$index}.key",
            '-http-ca-cert',
            "{$directory}/ca.crt",
            '-http-verify-client',
            '-node-cert',
            "{$directory}/node-{$index}.crt",
            '-node-key',
            "{$directory}/node-{$index}.key",
            '-node-ca-cert',
            "{$directory}/ca.crt",
            '-node-verify-client',
            '-node-verify-server-name',
            'raft.registry.baander.app',
            '-auth',
            "{$directory}/auth.json",
        ];
        if ($index !== 0) {
            array_push(
                $command,
                '-join',
                '127.0.0.1:' . $this->network->ports[0],
                '-join-as',
                'registry',
                '-join-attempts',
                '20',
                '-join-interval',
                '200ms',
            );
        }
        $command[] = $this->dataDirectory($index);
        foreach ([$index, $this->count + $index] as $reservation) {
            if (isset($this->reservations[$reservation])) {
                fclose($this->reservations[$reservation]);
                unset($this->reservations[$reservation]);
            }
        }
        if (isset($this->processes[$index])) {
            $this->processes[$index]->close(false);
        }
        $log = "{$directory}/node-{$index}.log";
        $this->logs[] = $log;
        $this->processes[$index] = new Process($command, $log);
    }
    /**
     * @param array<mixed>|null $statements
     * @return array{int,array<mixed>} */
    public function request(int $index, string $target, ?array $statements = null): array
    {
        [$status, $body] = Http::request(
            "node-{$index}.rqlite.baander.app",
            '127.0.0.1',
            $this->httpPorts[$index],
            $this->tls,
            $statements === null ? 'GET' : 'POST',
            $target,
            $statements === null ? null : json_encode($statements, JSON_THROW_ON_ERROR),
            [
                'Authorization' => 'Basic ' . base64_encode('registry:disposable-cluster-secret'),
                'Content-Type' => 'application/json',
            ],
        );
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = ['nonJson' => true];
        }
        return [$status, $decoded];
    }
    /**
     * @param list<int>|null $candidates */
    public function waitLeader(?array $candidates = null, float $seconds = 15): int
    {
        $until = Runtime::now() + $seconds;
        do {
            foreach ($candidates ?? range(0, $this->count - 1) as $index) {
                try {
                    [$status, $body] = $this->request($index, '/status');
                    if ($status === 200 && ($body['store']['raft']['state'] ?? null) === 'Leader') {
                        return $index;
                    }
                } catch (\Throwable) {
                }
            }
            usleep(50000);
        } while (Runtime::now() < $until);
        throw new \RuntimeException('Cluster did not elect a leader within the bound.');
    }
    public function start(): int
    {
        $this->startNode(0);
        $this->waitLeader([0]);
        for ($i = 1; $i < $this->count; ++$i) {
            $this->startNode($i);
        }
        $until = Runtime::now() + 20;
        do {
            $leader = $this->waitLeader();
            [$status, $nodes] = $this->request($leader, '/nodes');
            if ($status === 200 && count($nodes) === $this->count && array_all($nodes, static fn($node) => $node['voter'] ?? false)) {
                return $leader;
            }
            usleep(100000);
        } while (Runtime::now() < $until);
        throw new \RuntimeException('Not all expected voters joined.');
    }
    public function stopNodes(): void
    {
        $errors = [];
        foreach ($this->processes as $process) {
            try {
                $process->signal(15);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        foreach ($this->processes as $process) {
            try {
                try {
                    $process->wait(5);
                } catch (\RuntimeException) {
                    $process->signal(9);
                    $process->wait(3);
                }
                $process->close(false);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        Runtime::requireThat($errors === [], 'Voter shutdown failed: ' . implode('; ', $errors));
    }
    public function close(): void
    {
        $errors = [];
        foreach ([fn() => $this->stopNodes(), fn() => $this->network->close()] as $close) {
            try {
                $close();
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        foreach ($this->reservations as $socket) {
            fclose($socket);
        }
        $this->reservations = [];
        Runtime::requireThat($errors === [], 'Cluster cleanup failed: ' . implode('; ', $errors));
    }
}
