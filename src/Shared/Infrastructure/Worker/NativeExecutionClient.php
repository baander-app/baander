<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Swoole\Async;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class NativeExecutionClient implements NativeExecutionClientInterface
{
    private int $nextAttempt = 0;

    public function __construct(
        private readonly string $socketPath,
        private readonly string $namespace,
        private readonly string $bootId,
        private readonly int $leaseEpoch,
        private readonly string $clientId,
        private readonly int $clientGeneration,
    ) {
        if ($namespace === '' || preg_match('/^[a-f0-9]{32}$/D', $bootId) !== 1
            || $leaseEpoch < 1 || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $clientId) !== 1 || $clientGeneration < 1
        ) {
            throw new \InvalidArgumentException('Invalid native execution session.');
        }
    }

    public static function fromEnvironment(string $clientId, int $generation = 1): self
    {
        return new self(
            getenv('BAANDER_NATIVE_SOCKET') ?: '',
            getenv('BAANDER_WORKER_NAMESPACE') ?: '',
            getenv('BAANDER_WORKER_BOOT_ID') ?: '',
            (int) getenv('BAANDER_WORKER_LEASE_EPOCH'),
            $clientId,
            $generation,
        );
    }

    /** @return array<string, mixed> */
    public function handshake(): array
    {
        return $this->request('handshake');
    }

    /** @param list<string> $argv */
    public function submit(array $argv, float $executionTimeoutSeconds = 600, ?int $attempt = null): NativeExecutionHandle
    {
        $attempt ??= $this->nextAttempt + 1;
        $this->nextAttempt = max($this->nextAttempt, $attempt);
        $reply = $this->request('submit', ['argv' => $argv, 'timeout' => $executionTimeoutSeconds, 'attempt' => $attempt]);
        if (!is_string($reply['token'] ?? null)) {
            throw new NativeExecutionException('Native submission returned no handle.');
        }

        return new NativeExecutionHandle($reply['token'], $attempt);
    }

    /** @return array<string, mixed> */
    public function inspect(NativeExecutionHandle $handle): array
    {
        return $this->request('inspect', ['token' => $handle->token, 'attempt' => $handle->attempt]);
    }

    /** @return array<string, mixed> */
    public function signal(NativeExecutionHandle $handle, string $signal): array
    {
        return $this->request('signal', ['token' => $handle->token, 'attempt' => $handle->attempt, 'signal' => $signal]);
    }

    public function release(NativeExecutionHandle $handle): bool
    {
        return ($this->request('release', ['token' => $handle->token, 'attempt' => $handle->attempt])['released'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $operation, array $payload = []): array
    {
        $correlation = bin2hex(random_bytes(16));
        $identity = ['namespace' => $this->namespace, 'bootId' => $this->bootId, 'epoch' => $this->leaseEpoch,
            'clientId' => $this->clientId, 'generation' => $this->clientGeneration];
        $frame = NativeExecutionProtocol::encode(['id' => $correlation, ...$identity, 'operation' => $operation, ...$payload], NativeExecutionProtocol::REQUEST_BYTES);
        $deadline = hrtime(true) + 1_000_000_000;
        $socket = @stream_socket_client('unix://' . $this->socketPath, $errno, $error, 0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if ($socket === false) {
            throw new NativeExecutionException('Native execution socket is unavailable.');
        }
        stream_set_blocking($socket, false);
        $written = 0;
        $received = '';
        try {
            while (hrtime(true) < $deadline) {
                if ($written < strlen($frame)) {
                    $count = @fwrite($socket, substr($frame, $written));
                    if ($count === false) {
                        throw new NativeExecutionException('Native execution request write failed.');
                    }
                    $written += $count;
                }
                $chunk = @fread($socket, 65_536);
                if ($chunk === false) {
                    throw new NativeExecutionException('Native execution reply read failed.');
                }
                $received .= $chunk;
                if (strlen($received) > NativeExecutionProtocol::RESPONSE_BYTES) {
                    throw new NativeExecutionException('Native execution reply exceeds its byte limit.');
                }
                if (str_contains($received, "\n")) {
                    $reply = NativeExecutionProtocol::decode($received, NativeExecutionProtocol::RESPONSE_BYTES);
                    if (($reply['id'] ?? null) !== $correlation || ($reply['namespace'] ?? null) !== $this->namespace
                        || ($reply['bootId'] ?? null) !== $this->bootId || ($reply['epoch'] ?? null) !== $this->leaseEpoch
                    ) {
                        throw new NativeExecutionException('Native execution reply identity mismatch.');
                    }
                    if (($reply['ok'] ?? null) !== true) {
                        throw new NativeExecutionException(is_string($reply['error'] ?? null) ? $reply['error'] : 'Native execution request rejected.', ($reply['uncertain'] ?? true) !== false);
                    }
                    foreach (['stdout', 'stderr'] as $field) {
                        if (isset($reply[$field . '64'])) {
                            $encoded = $reply[$field . '64'];
                            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
                            if ($decoded === false || strlen($decoded) > NativeExecutionProtocol::OUTPUT_BYTES) {
                                throw new NativeExecutionException('Native output encoding is invalid.');
                            }
                            $reply[$field] = $decoded;
                            unset($reply[$field . '64']);
                        }
                    }

                    return $reply;
                }
                if (feof($socket)) {
                    throw new NativeExecutionException('Native execution peer closed without a reply.');
                }
                Async::sleep(0.001);
            }
            throw new NativeExecutionException('Native execution RPC deadline exceeded.');
        } finally {
            fclose($socket);
        }
    }
}
