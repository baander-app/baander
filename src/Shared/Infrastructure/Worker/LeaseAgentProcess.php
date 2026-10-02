<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** One fresh-exec lease operation. Observed output caps are not filesystem quotas. */
#[Exclude]
final class LeaseAgentProcess
{
    private const MAX_OUTPUT_BYTES = 8192;
    /** @var resource */
    private mixed $stdout;
    /** @var resource */
    private mixed $stderr;
    private WorkerChildProcess $child;
    private ?string $failure = null;
    private bool $complete = false;
    /** @var array{action: string, sequence: int, success: bool, category: string, lease: ?array{namespace: string, bootId: string, epoch: int}}|null */
    private ?array $result = null;
    private float $lastTime;

    /** @param array{action: string, sequence: int, namespace: string, bootId: string, epoch: ?int, ttlSeconds: int} $request */
    private function __construct(private readonly array $request, private readonly float $deadline, float $now)
    {
        $this->lastTime = $now;
    }

    /**
     * The directory and complete environment are trusted operator configuration.
     * Credentials belong only in DATABASE_URL, never the request or argv.
     * @param array<string, mixed> $request
     * @param array<string, string>|null $environment Null inherits the process environment; no .env loading
     */
    public static function start(array $request, string $projectDirectory, float $now, float $timeoutSeconds = 2.0, ?array $environment = null): self
    {
        $request = self::validateRequest($request);
        if (!is_finite($now) || $now < 0 || !is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 60 || !is_finite($now + $timeoutSeconds)) {
            throw new \InvalidArgumentException('Lease helper requires a monotonic start and a positive timeout of at most 60 seconds.');
        }
        $projectDirectory = rtrim($projectDirectory, '/');
        if (!str_starts_with($projectDirectory, '/') || realpath($projectDirectory) !== $projectDirectory || !is_file($projectDirectory . '/bin/worker-lease-agent.php')) {
            throw new \InvalidArgumentException('Lease helper requires a trusted canonical project directory containing its entrypoint.');
        }
        $agent = new self($request, $now + $timeoutSeconds, $now);
        $agent->stdout = tmpfile();
        $agent->stderr = tmpfile();
        if (!is_resource($agent->stdout) || !is_resource($agent->stderr)) {
            throw new \RuntimeException('Cannot allocate private lease helper output files.');
        }
        foreach ([$agent->stdout, $agent->stderr] as $stream) {
            if (!chmod(stream_get_meta_data($stream)['uri'], 0600)) {
                throw new \RuntimeException('Cannot secure lease helper output files.');
            }
        }
        $payload = base64_encode(json_encode($request, JSON_THROW_ON_ERROR));
        $agent->child = WorkerChildProcess::start([PHP_BINARY, '-d', 'memory_limit=64M', '-d', 'display_errors=0', '-d', 'log_errors=0', $projectDirectory . '/bin/worker-lease-agent.php', $payload], $projectDirectory, $agent->stdout, $agent->stderr, $environment);
        return $agent;
    }

    /**
     * @param array<string, mixed> $request
     * @return array{action: string, sequence: int, namespace: string, bootId: string, epoch: ?int, ttlSeconds: int}
     */
    public static function validateRequest(array $request): array
    {
        if (count($request) !== 6 || !isset($request['action'], $request['sequence'], $request['namespace'], $request['bootId'], $request['ttlSeconds']) || !array_key_exists('epoch', $request)
            || !in_array($request['action'], ['acquire', 'renew'], true) || !is_int($request['sequence']) || $request['sequence'] < 1
            || !is_string($request['namespace']) || !is_string($request['bootId']) || !is_int($request['ttlSeconds']) || $request['ttlSeconds'] < 1 || $request['ttlSeconds'] > 3600
            || ($request['action'] === 'acquire' ? $request['epoch'] !== null : (!is_int($request['epoch']) || $request['epoch'] < 1))) {
            throw new \InvalidArgumentException('Invalid lease helper request.');
        }
        DeploymentLease::validateIdentity($request['namespace'], $request['bootId']);
        return ['action' => $request['action'], 'sequence' => $request['sequence'], 'namespace' => $request['namespace'], 'bootId' => $request['bootId'], 'epoch' => $request['epoch'], 'ttlSeconds' => $request['ttlSeconds']];
    }

    /** True until the child is reaped. A completed reply first observed at/after the deadline is rejected. */
    public function poll(float $now): bool
    {
        $this->checkTime($now);
        if ($this->complete) {
            return false;
        }
        if ($this->failure === null && $now >= $this->deadline) {
            $this->failure = 'timeout';
        }
        if ($this->outputExceedsLimit()) {
            $this->failure ??= 'output_limit';
        }
        if ($this->failure !== null) {
            $this->child->requestStop($now, 0.1);
        }
        if ($this->child->poll($now)) {
            return true;
        }
        // The child may append its final bytes between the earlier check and
        // reaping. Validate both files again after no further writes are possible.
        if ($this->outputExceedsLimit()) {
            $this->failure ??= 'output_limit';
        }
        $this->complete = true;
        if ($this->failure !== null) {
            $this->result = $this->failureResult($this->failure);
        } elseif ($this->child->exitCode() !== 0) {
            $this->result = $this->failureResult('process_failed');
        } else {
            $this->result = $this->readResult();
        }
        return false;
    }

    public function cancel(float $now): void
    {
        $this->checkTime($now);
        if (!$this->complete) {
            $this->failure ??= 'cancelled';
            $this->child->requestStop($now, 0.1);
        }
    }

    /** @return array{action: string, sequence: int, success: bool, category: string, lease: ?array{namespace: string, bootId: string, epoch: int}}|null */
    public function takeResult(): ?array
    {
        $result = $this->result;
        $this->result = null;
        return $result;
    }

    private function outputExceedsLimit(): bool
    {
        foreach ([$this->stdout, $this->stderr] as $stream) {
            $stat = fstat($stream);
            if ($stat === false || $stat['size'] > self::MAX_OUTPUT_BYTES) {
                return true;
            }
        }
        return false;
    }

    /** @return array{action: string, sequence: int, success: bool, category: string, lease: ?array{namespace: string, bootId: string, epoch: int}} */
    private function readResult(): array
    {
        // Child descriptors share offsets. Open a separate read descriptor rather
        // than rewinding an inherited output descriptor while it is in use.
        $input = fopen(stream_get_meta_data($this->stdout)['uri'], 'rb');
        if ($input === false) {
            return $this->failureResult('invalid_response');
        }
        try {
            $raw = stream_get_contents($input, self::MAX_OUTPUT_BYTES + 1);
        } finally {
            fclose($input);
        }
        if ($raw === false) {
            return $this->failureResult('invalid_response');
        }
        if (strlen($raw) > self::MAX_OUTPUT_BYTES) {
            return $this->failureResult('output_limit');
        }
        try {
            $reply = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($reply) || count($reply) !== 5 || ($reply['action'] ?? null) !== $this->request['action'] || ($reply['sequence'] ?? null) !== $this->request['sequence']
                || !isset($reply['success'], $reply['category']) || !is_bool($reply['success']) || !is_string($reply['category']) || !array_key_exists('lease', $reply)) {
                return $this->failureResult('invalid_response');
            }
            if (!$reply['success']) {
                return in_array($reply['category'], ['denied', 'invalid_request', 'database_error'], true) && $reply['lease'] === null
                    ? $this->failureResult($reply['category']) : $this->failureResult('invalid_response');
            }
            $lease = $reply['lease'];
            if (!is_array($lease) || count($lease) !== 3 || ($lease['namespace'] ?? null) !== $this->request['namespace'] || ($lease['bootId'] ?? null) !== $this->request['bootId']
                || !isset($lease['epoch']) || !is_int($lease['epoch']) || $lease['epoch'] < 1 || $reply['category'] !== ($this->request['action'] === 'acquire' ? 'acquired' : 'renewed')
                || ($this->request['action'] === 'renew' && $lease['epoch'] !== $this->request['epoch'])) {
                return $this->failureResult('invalid_response');
            }
            return ['action' => $reply['action'], 'sequence' => $reply['sequence'], 'success' => true, 'category' => $reply['category'], 'lease' => ['namespace' => $lease['namespace'], 'bootId' => $lease['bootId'], 'epoch' => $lease['epoch']]];
        } catch (\JsonException) {
            return $this->failureResult('invalid_response');
        }
    }

    /** @return array{action: string, sequence: int, success: bool, category: string, lease: null} */
    private function failureResult(string $category): array
    {
        return ['action' => $this->request['action'], 'sequence' => $this->request['sequence'], 'success' => false, 'category' => $category, 'lease' => null];
    }

    private function checkTime(float $now): void
    {
        if (!is_finite($now) || $now < $this->lastTime) {
            throw new \InvalidArgumentException('Lease helper time must be finite and monotonic.');
        }
        $this->lastTime = $now;
    }

    public function __destruct()
    {
        // Drop the child first: its direct-child destructor kills/reaps before files close.
        unset($this->child);
        foreach ([$this->stdout ?? null, $this->stderr ?? null] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
