<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\WorkerLaunchIdentity;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Serializes receive admission independently of handlers and transport I/O. */
#[Exclude]
final class AdmissionLedger
{
    private const int MAX_BYTES = 16384;
    private readonly string $directory;
    private readonly string $profileHash;

    public function __construct(string $ipcDirectory, private readonly DeploymentLease $lease, private readonly WorkerAutoscalingProfile $profile)
    {
        $this->directory = rtrim($ipcDirectory, '/') . '/' . $lease->bootId;
        $this->profileHash = hash('sha256', $profile->toJson());
        $this->validateDirectory();
    }

    public function initialize(float $now): void
    {
        $this->time($now);
        $this->locked(function () use ($now): void {
            if (file_exists($this->path()) || is_link($this->path())) {
                $this->read();
                return;
            }
            $slots = [];
            foreach ($this->profile->consumerIds() as $id) {
                $slots[$id] = ['generation' => 1, 'sequence' => 0, 'requestId' => null, 'state' => 'missing',
                    'seenAt' => null, 'heldSince' => null, 'receivedAt' => null, 'handledAt' => null, 'fault' => null];
            }
            $this->write(['namespace' => $this->lease->namespace, 'bootId' => $this->lease->bootId,
                'epoch' => $this->lease->epoch, 'profileHash' => $this->profileHash, 'target' => 0, 'revision' => 0,
                'reason' => 'initializing', 'evidence' => [], 'controllerAt' => $now, 'fatal' => false,
                'completed' => 0, 'occupancyDurationEwmaMs' => null, 'handlerDurationEwmaMs' => null, 'slots' => $slots]);
        });
    }

    /** @param array<string, mixed> $evidence */
    public function publishDecision(int $target, string $reason, array $evidence, float $now): void
    {
        $this->time($now);
        if ($target < 0 || $target > $this->profile->maximumConcurrency || preg_match('/^[a-z][a-z0-9_-]{0,95}$/D', $reason) !== 1) {
            throw new \InvalidArgumentException('Invalid admission target or reason.');
        }
        $this->evidence($evidence);
        $this->update(function (array &$state) use ($target, $reason, $evidence, $now): void {
            if ($now < $state['controllerAt']) {
                throw new \RuntimeException('Out-of-order admission decision.');
            }
            $state['target'] = $state['fatal'] ? 0 : $target;
            $state['reason'] = $state['fatal'] ? 'uncertain-delivery' : $reason;
            $state['evidence'] = $evidence;
            $state['controllerAt'] = $now;
        });
    }

    public function acquire(WorkerLaunchIdentity $identity, int $sequence, string $requestId, float $now): bool
    {
        $this->identity($identity, $requestId, $now);
        return $this->update(function (array &$state) use ($identity, $sequence, $requestId, $now): bool {
            $slot = &$state['slots'][$identity->workerId];
            if ($sequence <= $slot['sequence'] || $slot['requestId'] !== null || ($slot['seenAt'] !== null && $now < $slot['seenAt'])) {
                throw new \RuntimeException('Stale or occupied admission request.');
            }
            foreach ($state['slots'] as $other) {
                if ($other['requestId'] === $requestId) {
                    throw new \RuntimeException('Duplicate admission request identity.');
                }
            }
            $slot['sequence'] = $sequence;
            $slot['seenAt'] = $now;
            $held = count(array_filter($state['slots'], static fn (array $value): bool => $value['requestId'] !== null));
            if ($state['fatal'] || $state['controllerAt'] > $now || $now - $state['controllerAt'] >= $this->profile->maxSampleAgeMs / 1000
                || $held >= $state['target'] || $held >= $this->profile->maximumConcurrency || !$this->peersReady($now)) {
                $slot['state'] = 'parked';
                return false;
            }
            $slot['requestId'] = $requestId;
            $slot['state'] = 'receiving';
            $slot['heldSince'] = $now;
            return true;
        });
    }

    public function received(WorkerLaunchIdentity $identity, string $requestId, float $now): void
    {
        $this->transition($identity, $requestId, $now, 'receiving', 'busy', 'receivedAt');
    }

    public function handled(WorkerLaunchIdentity $identity, string $requestId, float $now): void
    {
        $this->transition($identity, $requestId, $now, 'busy', 'completing', 'handledAt');
    }

    public function complete(WorkerLaunchIdentity $identity, string $requestId, float $now): void
    {
        $this->identity($identity, $requestId, $now);
        $this->update(function (array &$state) use ($identity, $requestId, $now): void {
            $slot = &$state['slots'][$identity->workerId];
            $this->owned($slot, $requestId, $now);
            if (!in_array($slot['state'], ['receiving', 'completing'], true)) {
                throw new \RuntimeException('Delivery completion requires an empty receive or a completed handler.');
            }
            if ($slot['state'] === 'completing') {
                ++$state['completed'];
                $state['occupancyDurationEwmaMs'] = $this->ewma($state['occupancyDurationEwmaMs'], ($now - $slot['heldSince']) * 1000);
                $state['handlerDurationEwmaMs'] = $this->ewma($state['handlerDurationEwmaMs'], ($slot['handledAt'] - $slot['receivedAt']) * 1000);
            }
            $slot['state'] = 'idle';
            $slot['requestId'] = $slot['heldSince'] = $slot['receivedAt'] = $slot['handledAt'] = null;
            $slot['seenAt'] = $now;
        });
    }

    public function fault(WorkerLaunchIdentity $identity, string $requestId, string $code, float $now): void
    {
        $this->identity($identity, $requestId, $now);
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Invalid admission fault code.');
        }
        $this->update(function (array &$state) use ($identity, $requestId, $code, $now): void {
            $slot = &$state['slots'][$identity->workerId];
            $this->owned($slot, $requestId, $now);
            $slot['state'] = 'uncertain';
            $slot['fault'] = $code;
            $slot['seenAt'] = $now;
            $state['fatal'] = true;
            $state['target'] = 0;
            $state['reason'] = 'uncertain-delivery';
        });
    }

    /** @return array<string, mixed> */
    public function snapshot(float $now): array
    {
        $this->time($now);
        return $this->locked(fn (): array => $this->read());
    }

    public function authorityValid(float $now): bool
    {
        $this->time($now);
        return $this->peersReady($now);
    }

    private function transition(WorkerLaunchIdentity $identity, string $requestId, float $now, string $from, string $to, string $timestamp): void
    {
        $this->identity($identity, $requestId, $now);
        $this->update(function (array &$state) use ($identity, $requestId, $now, $from, $to, $timestamp): void {
            $slot = &$state['slots'][$identity->workerId];
            $this->owned($slot, $requestId, $now);
            if ($slot['state'] !== $from) {
                throw new \RuntimeException('Unexpected delivery lifecycle transition.');
            }
            $slot['state'] = $to;
            $slot[$timestamp] = $slot['seenAt'] = $now;
        });
    }

    private function identity(WorkerLaunchIdentity $identity, string $requestId, float $now): void
    {
        $this->time($now);
        if ($identity->deploymentId !== $this->lease->namespace || $identity->supervisorBootId !== $this->lease->bootId
            || $identity->generation !== 1 || !in_array($identity->workerId, $this->profile->consumerIds(), true)
            || preg_match('/^[a-f0-9]{32}$/D', $requestId) !== 1) {
            throw new \InvalidArgumentException('Foreign worker launch or invalid admission request.');
        }
    }

    /** @param array<string, mixed> $slot */
    private function owned(array $slot, string $requestId, float $now): void
    {
        if ($slot['requestId'] !== $requestId || ($slot['seenAt'] !== null && $now < $slot['seenAt']) || $slot['state'] === 'uncertain') {
            throw new \RuntimeException('Stale, uncertain or unowned delivery operation.');
        }
    }

    private function time(float $now): void
    {
        if (!is_finite($now) || $now < 0) {
            throw new \InvalidArgumentException('Admission timestamps require nonnegative monotonic time.');
        }
    }

    private function ewma(?float $previous, float $sample): float
    {
        return $previous === null ? $sample : $previous * 0.75 + $sample * 0.25;
    }

    /**
     * @template T
     * @param callable(array<string, mixed>): T $operation
     * @return T
     */
    private function update(callable $operation): mixed
    {
        return $this->locked(function () use ($operation): mixed {
            $state = $this->read();
            $result = $operation($state);
            ++$state['revision'];
            $this->write($state);
            return $result;
        });
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function locked(callable $operation): mixed
    {
        $this->validateDirectory();
        $path = $this->directory . '/admission.lock';
        if (!file_exists($path) && !is_link($path)) {
            $created = @fopen($path, 'x');
            if (is_resource($created)) {
                chmod($path, 0600);
                fclose($created);
            }
        }
        $this->privateFile($path);
        $lock = @fopen($path, 'r+');
        if ($lock === false) {
            throw new \RuntimeException('Admission lock unavailable.');
        }
        try {
            $deadline = hrtime(true) + 50_000_000;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new \RuntimeException('Admission lock deadline exceeded.');
                }
                usleep(1000);
            }
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function validateDirectory(): void
    {
        clearstatcache(true, $this->directory);
        if (!str_starts_with($this->directory, '/') || is_link($this->directory) || realpath($this->directory) !== $this->directory
            || fileowner($this->directory) !== fileowner('/proc/self') || (fileperms($this->directory) & 0777) !== 0700) {
            throw new \RuntimeException('Admission requires an existing private canonical boot directory.');
        }
    }

    private function privateFile(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || fileowner($path) !== fileowner('/proc/self') || (fileperms($path) & 0777) !== 0600) {
            throw new \RuntimeException('Invalid private admission file.');
        }
    }

    private function path(): string
    {
        return $this->directory . '/admission.json';
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $this->privateFile($this->path());
        $raw = file_get_contents($this->path(), false, null, 0, self::MAX_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_BYTES) {
            throw new \RuntimeException('Admission state exceeds its read bound.');
        }
        $state = json_decode($raw, true, 10, JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            throw new \RuntimeException('Invalid admission state.');
        }
        $this->validate($state);
        return $state;
    }

    /** @param array<string, mixed> $state */
    private function write(array $state): void
    {
        $this->validate($state);
        $raw = json_encode($state, JSON_THROW_ON_ERROR);
        if (strlen($raw) > self::MAX_BYTES) {
            throw new \RuntimeException('Admission state exceeds its write bound.');
        }
        $temporary = tempnam($this->directory, '.admission-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot allocate admission state.');
        }
        try {
            if (!chmod($temporary, 0600) || file_put_contents($temporary, $raw) !== strlen($raw) || !rename($temporary, $this->path())) {
                throw new \RuntimeException('Cannot publish admission state.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @param array<string, mixed> $state */
    private function validate(array $state): void
    {
        if (count($state) !== 14 || ($state['namespace'] ?? null) !== $this->lease->namespace || ($state['bootId'] ?? null) !== $this->lease->bootId
            || ($state['epoch'] ?? null) !== $this->lease->epoch || ($state['profileHash'] ?? null) !== $this->profileHash
            || !is_int($state['target'] ?? null) || $state['target'] < 0 || $state['target'] > $this->profile->maximumConcurrency
            || !is_int($state['revision'] ?? null) || $state['revision'] < 0 || !is_bool($state['fatal'] ?? null)
            || !is_string($state['reason'] ?? null) || preg_match('/^[a-z][a-z0-9_-]{0,95}$/D', $state['reason']) !== 1
            || !is_array($state['evidence'] ?? null) || !is_int($state['completed'] ?? null) || $state['completed'] < 0
            || !is_array($state['slots'] ?? null) || array_keys($state['slots']) !== $this->profile->consumerIds()
            || ($state['fatal'] && $state['target'] !== 0)) {
            throw new \RuntimeException('Foreign or corrupt admission state.');
        }
        $this->number($state['controllerAt'] ?? null);
        $this->evidence($state['evidence']);
        foreach (['occupancyDurationEwmaMs', 'handlerDurationEwmaMs'] as $key) {
            if (!array_key_exists($key, $state)) {
                throw new \RuntimeException('Missing admission duration.');
            }
            if ($state[$key] !== null) { $this->number($state[$key]); }
        }
        $requests = [];
        foreach ($state['slots'] as $slot) {
            if (!is_array($slot) || count($slot) !== 9 || ($slot['generation'] ?? null) !== 1
                || !is_int($slot['sequence'] ?? null) || $slot['sequence'] < 0
                || !in_array($slot['state'] ?? null, ['missing', 'idle', 'parked', 'receiving', 'busy', 'completing', 'uncertain'], true)) {
                throw new \RuntimeException('Invalid admission slot.');
            }
            if ($slot['state'] === 'missing') {
                if (($slot['seenAt'] ?? null) !== null || $slot['sequence'] !== 0) { throw new \RuntimeException('Invalid missing admission slot.'); }
            } else {
                $this->number($slot['seenAt'] ?? null);
            }
            foreach (['requestId', 'seenAt', 'heldSince', 'receivedAt', 'handledAt', 'fault'] as $key) {
                if (!array_key_exists($key, $slot)) { throw new \RuntimeException('Incomplete admission slot.'); }
            }
            $held = in_array($slot['state'], ['receiving', 'busy', 'completing', 'uncertain'], true);
            if (($held && (!is_string($slot['requestId']) || preg_match('/^[a-f0-9]{32}$/D', $slot['requestId']) !== 1))
                || (!$held && ($slot['requestId'] !== null || $slot['heldSince'] !== null || $slot['receivedAt'] !== null || $slot['handledAt'] !== null))
                || ($held && in_array($slot['requestId'], $requests, true))) {
                throw new \RuntimeException('Invalid admission ownership.');
            }
            if ($held) {
                $requests[] = $slot['requestId'];
                $this->number($slot['heldSince']);
                if ($slot['heldSince'] > $slot['seenAt']) { throw new \RuntimeException('Invalid admission times.'); }
            }
            foreach (['receivedAt', 'handledAt'] as $key) {
                if ($slot[$key] !== null) { $this->number($slot[$key]); }
            }
            if ((in_array($slot['state'], ['busy', 'completing'], true) && ($slot['receivedAt'] === null || $slot['receivedAt'] < $slot['heldSince'] || $slot['receivedAt'] > $slot['seenAt']))
                || ($slot['state'] === 'completing' && ($slot['handledAt'] === null || $slot['handledAt'] < $slot['receivedAt'] || $slot['handledAt'] > $slot['seenAt']))
                || ($slot['state'] === 'uncertain' && (!$state['fatal'] || !is_string($slot['fault']) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $slot['fault']) !== 1))
                || ($slot['state'] !== 'uncertain' && $slot['fault'] !== null)) {
                throw new \RuntimeException('Invalid admission lifecycle.');
            }
        }
    }

    private function number(mixed $value): void
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
            throw new \RuntimeException('Invalid admission measurement.');
        }
    }

    /** @param array<array-key, mixed> $evidence */
    private function evidence(array $evidence): void
    {
        if (strlen(json_encode($evidence, JSON_THROW_ON_ERROR)) > 2048 || count($evidence) > 32) {
            throw new \InvalidArgumentException('Admission decision evidence exceeds its bound.');
        }
        foreach ($evidence as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,47}$/D', $key) !== 1
                || !(is_null($value) || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value)) || (is_string($value) && strlen($value) <= 128))) {
                throw new \InvalidArgumentException('Admission evidence requires bounded scalar measurements.');
            }
        }
    }

    private function peersReady(float $now): bool
    {
        foreach (['control', 'execution'] as $unit) {
            $path = $this->directory . '/' . $unit . '.json';
            if (!is_file($path)) { return false; }
            $this->privateFile($path);
            $raw = file_get_contents($path, false, null, 0, 2049);
            if ($raw === false || strlen($raw) > 2048) { throw new \RuntimeException('Invalid admission peer heartbeat.'); }
            $peer = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($peer) || count($peer) !== 7 || ($peer['namespace'] ?? null) !== $this->lease->namespace
                || ($peer['bootId'] ?? null) !== $this->lease->bootId || ($peer['epoch'] ?? null) !== $this->lease->epoch || ($peer['unit'] ?? null) !== $unit) {
                throw new \RuntimeException('Foreign admission peer heartbeat.');
            }
            $this->number($peer['time'] ?? null);
            if (($peer['ready'] ?? null) !== true || ($peer['draining'] ?? null) !== false || $peer['time'] > $now || $now - $peer['time'] >= 2.0) { return false; }
        }
        return true;
    }
}
