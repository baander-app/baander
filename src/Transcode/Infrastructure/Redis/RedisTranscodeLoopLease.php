<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Redis;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Transcode\Application\Port\TranscodeLoopLeaseInterface;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Captures an immutable owner token, independently of subsequent acquisitions. */
final class RedisTranscodeLoopLease implements TranscodeLoopLeaseInterface
{
    private const string RENEW_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('EXPIRE', KEYS[1], ARGV[2])
end
return 0
LUA;

    private const string RELEASE_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    private bool $canRenew = true;
    private bool $released = false;

    public function __construct(
        private readonly RedisClientFactory $redis,
        private readonly Uuid $jobId,
        private readonly string $key,
        #[SensitiveParameter] private readonly string $token,
    ) {
    }

    public function getJobId(): Uuid
    {
        return $this->jobId;
    }

    public function renew(int $ttlSeconds): bool
    {
        if (!$this->canRenew) {
            return false;
        }

        try {
            $renewed = (bool) $this->redis->borrow(function (\Redis $redis) use ($ttlSeconds): bool {
                return $redis->eval(self::RENEW_SCRIPT, [$this->key, $this->token, max(1, $ttlSeconds)], 1) === 1;
            });
        } catch (Throwable) {
            $renewed = false;
        }

        if (!$renewed) {
            $this->canRenew = false;
        }

        return $renewed && $this->canStillRenew();
    }

    /** Redis I/O may yield while another operation retires this handle. */
    private function canStillRenew(): bool
    {
        return $this->canRenew;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->canRenew = false;
        $this->released = true;
        try {
            $this->redis->borrow(function (\Redis $redis): void {
                $redis->eval(self::RELEASE_SCRIPT, [$this->key, $this->token], 1);
            });
        } catch (Throwable) {
            // The key expires via TTL if Redis is unavailable during cleanup.
        }
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('Transcode loop leases cannot be serialized.');
    }

    /** @return array{jobId: string, canRenew: bool, released: bool} */
    public function __debugInfo(): array
    {
        return [
            'jobId' => $this->jobId->toString(),
            'canRenew' => $this->canRenew,
            'released' => $this->released,
        ];
    }
}
