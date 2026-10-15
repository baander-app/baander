<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\RateLimiter;

use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterStore;

final class RateLimiterFixture
{
    /** @return array{id: string, policy: string, limit: int, interval: string} */
    public static function config(string $name, string $policy): array
    {
        return ['id' => $name, 'policy' => $policy, 'limit' => 2, 'interval' => '60 seconds'];
    }

    public static function subjectDigest(string $name, string $policy, string $subject): string
    {
        return hash('sha256', serialize(self::config($name, $policy)) . "\0" . $subject);
    }

    /** @return list<string> */
    public static function keys(string $name): array
    {
        $namespace = RedisRateLimiterStore::namespace($name);
        $keys = [$namespace . ':generation'];
        foreach (['subject', 'other'] as $subject) {
            // The direct-store race tests use a digest without a policy configuration.
            $keys[] = $namespace . ':subject:' . hash('sha256', $subject);
            foreach (['fixed_window', 'sliding_window'] as $policy) {
                $keys[] = $namespace . ':subject:' . self::subjectDigest($name, $policy, $subject);
            }
        }

        return $keys;
    }
}
