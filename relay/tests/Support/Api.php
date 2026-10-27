<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class Api
{
    public int $port;
    public Process $process;
    /**
     * @param list<array{int,int}> $endpoints */
    public function __construct(public Cluster $cluster, string $server, string $name, array $endpoints, int $databaseConnections = 1)
    {
        $this->port = Runtime::port();
        $directory = $cluster->directory;
        $config = [
            'api' => [
                'port' => $this->port,
                'certificate' => $cluster->apiCert,
                'key' => $cluster->apiKey,
                'enrollmentKeyFile' => $cluster->enrollmentKeyFile,
                'deadlineMs' => 5000,
            ],
            'database' => [
                'endpoints' => array_map(
                    static fn($endpoint) => [
                        'url' => "https://node-{$endpoint[0]}.rqlite.baander.app:{$endpoint[1]}",
                        'connectAddress' => '127.0.0.1',
                    ],
                    $endpoints,
                ),
                'ca' => "{$directory}/ca.crt",
                'certificate' => $cluster->clientCert,
                'key' => $cluster->clientKey,
                'username' => 'registry',
                'passwordFile' => "{$directory}/password",
                'connections' => $databaseConnections,
                'deadlineMs' => 2000,
            ],
        ];
        $path = "{$directory}/{$name}.json";
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
        $this->process = new Process([$server, '--config', $path], "{$directory}/{$name}.log");
        try {
            $this->waitReady();
        } catch (\Throwable $e) {
            $this->close(false);
            throw $e;
        }
    }
    /**
     * @param array<mixed>|null $body
     * @return array{int,array<mixed>,array<string,string>} */
    public function request(string $method, string $target, ?array $body = null, bool|string $enrollment = true): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($method === 'POST' && $target === '/api/servers/register' && $enrollment !== false) {
            $headers['X-Baander-Enrollment'] = is_string($enrollment)
                ? $enrollment
                : Enrollment::issueToken(
                    $this->cluster->enrollmentKey,
                    $body,
                    (int) (microtime(true) * 1000) + 60000,
                );
        }
        [$status, $payload, $headers] = Http::request(
            'api.registry.baander.app',
            '127.0.0.1',
            $this->port,
            $this->cluster->apiTls,
            $method,
            $target,
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
            $headers,
            6,
        );
        return [$status, json_decode($payload, true, flags: JSON_THROW_ON_ERROR), $headers];
    }
    public function waitReady(): void
    {
        $until = Runtime::now() + 20;
        do {
            Runtime::requireThat($this->process->poll() === null, 'Registry process exited before readiness.');
            try {
                if ($this->request('GET', '/ready')[0] === 200) {
                    return;
                }
            } catch (\Throwable) {
            }
            usleep(50000);
        } while (Runtime::now() < $until);
        throw new \RuntimeException('Registry did not become ready.');
    }
    public function close(bool $checkExit = true): void
    {
        $this->process->close($checkExit);
    }
}
