<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Redis;

use Redis;
use RedisException;

/** Keeps string keys in memory; `unreachable` makes every command fail as a lost connection does. */
final class ArrayRedis extends Redis
{
    /** @var array<string, string> */
    public array $values = [];
    public bool $unreachable = false;

    public function get(string $key): mixed
    {
        $this->assertReachable();

        return $this->values[$key] ?? false;
    }

    /** @param array<mixed>|int|null $options */
    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        $this->assertReachable();
        $this->values[$key] = (string) $value;

        return true;
    }

    public function ping(?string $message = null): string
    {
        $this->assertReachable();

        return 'PONG';
    }

    public function dbSize(): int
    {
        $this->assertReachable();

        return count($this->values);
    }

    private function assertReachable(): void
    {
        if ($this->unreachable) {
            throw new RedisException('Connection refused');
        }
    }
}
