<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Fresh execution-container CLI process; all native process ownership stays here. */
#[Exclude]
final class NativeExecutionDaemon
{
    /** @var array<string, NativeExecutionAttempt> */
    private array $attempts = [];
    /** @var array<string, array{generation:int, highest:int}> */
    private array $clients = [];
    private bool $stopping = false;
    private bool $poisoned = false;

    public function __construct(private readonly string $namespace, private readonly string $bootId, private readonly int $epoch)
    {
        if ($namespace === '' || preg_match('/^[a-f0-9]{32}$/D', $bootId) !== 1 || $epoch < 1) {
            throw new \InvalidArgumentException('Invalid native launcher identity.');
        }
    }

    public function run(string $socketPath): int
    {
        $directory = dirname($socketPath);
        if (strlen($socketPath) > 107 || $socketPath === '') {
            throw new \RuntimeException('Invalid native launcher socket path.');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true)) {
            clearstatcache(true, $directory);
            if (!is_dir($directory)) {
                throw new \RuntimeException('Native launcher socket directory could not be created.');
            }
        }
        if (is_link($directory) || fileowner($directory) !== posix_geteuid() || (fileperms($directory) & 0777) !== 0700
            || file_exists($socketPath) || is_link($socketPath)
        ) {
            throw new \RuntimeException('Native launcher requires a fresh socket in an owned private directory.');
        }
        $server = @stream_socket_server('unix://' . $socketPath, $errno, $error);
        if ($server === false) {
            throw new \RuntimeException('Native launcher could not bind its socket.');
        }
        if (!chmod($socketPath, 0600)) {
            fclose($server);
            throw new \RuntimeException('Native launcher could not restrict its socket.');
        }
        stream_set_blocking($server, false);
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void { $this->stopping = true; });
        pcntl_signal(SIGINT, function (): void { $this->stopping = true; });
        /** @var array<int, array{socket:resource, input:string, output:?string, written:int, deadline:float}> $peers */
        $peers = [];
        try {
            while (!$this->stopping && !$this->poisoned) {
                $this->tick();
                if ($this->stopping || $this->poisoned) {
                    break;
                }
                $peer = @stream_socket_accept($server, 0);
                if ($peer !== false) {
                    if (count($peers) >= 16) {
                        fclose($peer);
                    } else {
                        stream_set_blocking($peer, false);
                        $peers[(int) $peer] = ['socket' => $peer, 'input' => '', 'output' => null, 'written' => 0, 'deadline' => self::now() + 1];
                    }
                }
                foreach ($peers as $key => &$connection) {
                    $close = self::now() >= $connection['deadline'];
                    if (!$close && $connection['output'] === null) {
                        $chunk = @fread($connection['socket'], 16_384);
                        if ($chunk === false || ($chunk === '' && feof($connection['socket']))) {
                            $close = true;
                        } else {
                            $connection['input'] .= $chunk;
                            if (strlen($connection['input']) > NativeExecutionProtocol::REQUEST_BYTES) {
                                $close = true;
                            } elseif (str_contains($connection['input'], "\n")) {
                                $connection['output'] = $this->reply($connection['input']);
                            }
                        }
                    }
                    if (!$close && $connection['output'] !== null) {
                        $sent = @fwrite($connection['socket'], substr($connection['output'], $connection['written'], 65_536));
                        if ($sent === false) {
                            $close = true;
                        } else {
                            $connection['written'] += $sent;
                            $close = $connection['written'] === strlen($connection['output']);
                        }
                    }
                    if ($close) {
                        fclose($connection['socket']);
                        unset($peers[$key]);
                    }
                }
                unset($connection);
                usleep(1_000);
            }
        } finally {
            fclose($server);
            foreach ($peers as $connection) {
                fclose($connection['socket']);
            }
            $this->drain();
            @unlink($socketPath);
        }

        return $this->poisoned ? 1 : 0;
    }

    private function reply(string $frame): string
    {
        $id = null;
        try {
            $request = NativeExecutionProtocol::decode($frame, NativeExecutionProtocol::REQUEST_BYTES);
            $id = $request['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
                throw new NativeExecutionException('Invalid native request correlation.', false);
            }
            $session = $this->session($request);
            $result = $this->operate($request, $session);
            $reply = ['ok' => true, ...$result];
        } catch (NativeExecutionException $error) {
            $reply = ['ok' => false, 'error' => $error->getMessage(), 'uncertain' => $error->uncertain];
        } catch (\Throwable) {
            $reply = ['ok' => false, 'error' => 'Native launcher failed to process request.', 'uncertain' => true];
            $this->poisoned = true;
        }

        foreach (['stdout', 'stderr'] as $field) {
            if (isset($reply[$field]) && is_string($reply[$field])) {
                $reply[$field . '64'] = base64_encode($reply[$field]);
                unset($reply[$field]);
            }
        }

        return NativeExecutionProtocol::encode(['id' => $id, 'namespace' => $this->namespace, 'bootId' => $this->bootId,
            'epoch' => $this->epoch, ...$reply], NativeExecutionProtocol::RESPONSE_BYTES);
    }

    /** @param array<string, mixed> $request */
    private function session(array $request): string
    {
        if (($request['namespace'] ?? null) !== $this->namespace || ($request['bootId'] ?? null) !== $this->bootId
            || ($request['epoch'] ?? null) !== $this->epoch
        ) {
            throw new NativeExecutionException('Native request authority mismatch.');
        }
        $client = $request['clientId'] ?? null;
        $generation = $request['generation'] ?? null;
        if (!is_string($client) || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $client) !== 1 || !is_int($generation) || $generation < 1) {
            throw new NativeExecutionException('Invalid native client identity.', false);
        }
        $previous = $this->clients[$client] ?? null;
        if ($previous === null && count($this->clients) >= NativeExecutionProtocol::MAX_CLIENTS) {
            throw new NativeExecutionException('Native client ledger is full.', false);
        }
        if ($previous !== null && $generation < $previous['generation']) {
            throw new NativeExecutionException('Stale native client generation.');
        }
        if ($previous !== null && $generation > $previous['generation']) {
            foreach ($this->attempts as $attempt) {
                if ($attempt->session === $client . ':' . $previous['generation'] && $attempt->state === 'running') {
                    $this->poisoned = true;
                    throw new NativeExecutionException('A client generation changed with native work still owned.');
                }
            }
            foreach ($this->attempts as $token => $attempt) {
                if ($attempt->session === $client . ':' . $previous['generation']) {
                    unset($this->attempts[$token]);
                }
            }
        }
        if ($previous === null || $previous['generation'] !== $generation) {
            $this->clients[$client] = ['generation' => $generation, 'highest' => 0];
        }

        return $client . ':' . $generation;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function operate(array $request, string $session): array
    {
        $operation = $request['operation'] ?? null;
        $allowed = ['id', 'namespace', 'bootId', 'epoch', 'clientId', 'generation', 'operation'];
        $allowed = match ($operation) {
            'submit' => [...$allowed, 'argv', 'timeout', 'attempt'],
            'inspect', 'release' => [...$allowed, 'token', 'attempt'],
            'signal' => [...$allowed, 'token', 'attempt', 'signal'],
            'handshake' => $allowed,
            default => throw new NativeExecutionException('Unsupported native operation.', false),
        };
        if (array_diff(array_keys($request), $allowed) !== []) {
            throw new NativeExecutionException('Unsupported native request fields.', false);
        }
        if ($operation === 'handshake') {
            return ['ready' => !$this->poisoned && !$this->stopping];
        }
        if ($operation === 'submit') {
            return $this->submit($request, $session);
        }
        $token = $request['token'] ?? null;
        $sequence = $request['attempt'] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 || !is_int($sequence) || $sequence < 1) {
            throw new NativeExecutionException('Invalid native attempt identity.', false);
        }
        $attempt = $this->attempts[$token] ?? null;
        if ($attempt === null || $attempt->session !== $session || $attempt->attempt !== $sequence) {
            return ['state' => 'unknown', 'stdout' => '', 'stderr' => '', 'exitCode' => null, 'error' => 'Unknown native attempt.', 'released' => false];
        }
        if ($operation === 'release') {
            if ($attempt->state !== 'exited') {
                return ['released' => false];
            }
            unset($this->attempts[$token]);

            return ['released' => true];
        }
        if ($operation === 'signal') {
            $signal = match ($request['signal'] ?? null) {
                'pause' => SIGSTOP, 'resume' => SIGCONT, 'stop' => SIGKILL,
                default => throw new NativeExecutionException('Unsupported native signal.', false),
            };
            if ($attempt->state === 'running') {
                if (!posix_kill(-$attempt->pid, $signal)) {
                    // Admission can precede the child's setsid. Keep the captured signal
                    // pending without treating a missing group as successful containment.
                    $attempt->pendingSignal = $signal;
                    $attempt->signalDeadline ??= self::now() + 1;
                    if ($signal === SIGKILL) {
                        @proc_terminate($attempt->process, SIGKILL);
                    }
                } else {
                    $attempt->pendingSignal = null;
                    $attempt->signalDeadline = null;
                }
                if ($signal === SIGKILL) {
                    $attempt->stopDeadline ??= self::now() + 1;
                }
            }
        }

        return $attempt->snapshot();
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function submit(array $request, string $session): array
    {
        if ($this->stopping || $this->poisoned) {
            throw new NativeExecutionException('Native launcher has stopped admission.');
        }
        $argv = $request['argv'] ?? null;
        $timeout = $request['timeout'] ?? null;
        $sequence = $request['attempt'] ?? null;
        $client = $request['clientId'];
        if (!is_string($client) || !is_int($sequence) || $sequence < 1) {
            throw new NativeExecutionException('Invalid native submission attempt.', false);
        }
        $existing = null;
        foreach ($this->attempts as $candidate) {
            if ($candidate->session === $session && $candidate->attempt === $sequence) {
                $existing = $candidate;
                break;
            }
        }
        if ($existing === null) {
            if ($sequence <= $this->clients[$client]['highest']) {
                throw new NativeExecutionException('Native attempt was released, rejected or superseded.');
            }
            $this->clients[$client]['highest'] = $sequence;
        }
        if (!is_array($argv) || !array_is_list($argv) || $argv === [] || count($argv) > 512
            || (!is_int($timeout) && !is_float($timeout)) || !is_finite((float) $timeout) || $timeout <= 0 || $timeout > 86400
        ) {
            throw new NativeExecutionException('Invalid native submission.', false);
        }
        foreach ($argv as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new NativeExecutionException('Native argv must contain only strings without NUL bytes.', false);
            }
        }
        $executable = match ($argv[0]) {
            'ffmpeg', '/usr/bin/ffmpeg' => '/usr/bin/ffmpeg',
            'ffprobe', '/usr/bin/ffprobe' => '/usr/bin/ffprobe',
            default => throw new NativeExecutionException('Native executable is not allowed.', false),
        };
        $argv[0] = $executable;
        $fingerprint = hash('sha256', json_encode([$argv, (float) $timeout], JSON_THROW_ON_ERROR));
        if ($existing !== null) {
            if ($existing->fingerprint !== $fingerprint) {
                throw new NativeExecutionException('A native attempt cannot be reused for a different command.');
            }

            return $existing->snapshot();
        }
        $active = count(array_filter($this->attempts, static fn (NativeExecutionAttempt $attempt): bool => $attempt->state === 'running'));
        if ($active >= NativeExecutionProtocol::MAX_COMMANDS || count($this->attempts) >= NativeExecutionProtocol::MAX_RESULTS) {
            throw new NativeExecutionException('Native command or result capacity is exhausted.', false);
        }
        $process = @proc_open(['/usr/bin/setsid', ...$argv], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'LC_ALL' => 'C'], ['bypass_shell' => true]);
        if ($process === false) {
            $this->poisoned = true;
            throw new NativeExecutionException('Native command launch failed.');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $status = proc_get_status($process);
        $token = bin2hex(random_bytes(32));
        $attempt = new NativeExecutionAttempt($token, $session, $sequence, $fingerprint, $status['pid'], $process, $pipes, self::now() + $timeout);
        $this->attempts[$token] = $attempt;

        return $attempt->snapshot();
    }

    private function tick(): void
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->state !== 'running') {
                continue;
            }
            if ($attempt->pendingSignal !== null) {
                if (posix_kill(-$attempt->pid, $attempt->pendingSignal)) {
                    $attempt->pendingSignal = null;
                    $attempt->signalDeadline = null;
                } elseif ($attempt->signalDeadline !== null && self::now() >= $attempt->signalDeadline) {
                    $this->poisoned = true;
                    $attempt->error = 'Native process group signalling was not proven.';
                }
            }
            foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $field) {
                $chunk = @fread($attempt->pipes[$index], 65_536);
                if ($chunk === false) {
                    $this->poisoned = true;
                    $attempt->error = 'Native output drainage failed.';
                } elseif (strlen($attempt->{$field}) + strlen($chunk) > NativeExecutionProtocol::OUTPUT_BYTES) {
                    $attempt->error = 'Native output byte limit exceeded.';
                    $this->poisoned = true;
                } else {
                    $attempt->{$field} .= $chunk;
                }
            }
            if (self::now() >= $attempt->deadline && $attempt->stopDeadline === null) {
                $attempt->error = 'Native execution deadline exceeded.';
                @posix_kill(-$attempt->pid, SIGKILL);
                $attempt->stopDeadline = self::now() + 1;
            }
            $status = proc_get_status($attempt->process);
            if (!$status['running']) {
                // Drain the remaining bounded pipe buffers before publishing the terminal receipt.
                foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $field) {
                    $tail = stream_get_contents($attempt->pipes[$index], NativeExecutionProtocol::OUTPUT_BYTES + 1);
                    if ($tail === false || strlen($attempt->{$field}) + strlen($tail) > NativeExecutionProtocol::OUTPUT_BYTES) {
                        $attempt->error = 'Native output byte limit exceeded.';
                        $this->poisoned = true;
                    } else {
                        $attempt->{$field} .= $tail;
                    }
                    fclose($attempt->pipes[$index]);
                }
                $attempt->pipes = [];
                $attempt->exitCode = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
                proc_close($attempt->process);
                if (posix_kill(-$attempt->pid, 0)) {
                    @posix_kill(-$attempt->pid, SIGKILL);
                    $attempt->error = 'Native descendants remain after command exit.';
                    $this->poisoned = true;
                    $attempt->state = 'unknown';
                } else {
                    $attempt->state = 'exited';
                }
            } elseif ($attempt->stopDeadline !== null && self::now() >= $attempt->stopDeadline) {
                $this->poisoned = true;
            }
        }
    }

    private function drain(): void
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->state === 'running') {
                @posix_kill(-$attempt->pid, SIGKILL);
                // Cover the short pre-setsid launch window as well.
                @proc_terminate($attempt->process, SIGKILL);
                $attempt->stopDeadline = self::now() + 1;
            }
        }
        $deadline = self::now() + 1;
        do {
            $this->tick();
            $active = array_filter($this->attempts, static fn (NativeExecutionAttempt $attempt): bool => $attempt->state === 'running');
            if ($active === []) {
                return;
            }
            usleep(1_000);
        } while (self::now() < $deadline);
        $this->poisoned = true;
        // Container containment owns any child the kernel has not made reapable.
    }

    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
