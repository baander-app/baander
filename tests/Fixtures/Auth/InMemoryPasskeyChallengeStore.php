<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Application\Port\PasskeyChallengeStoreInterface;

/**
 * The challenge store as Redis behaves for one test: shared by every service given
 * the same instance, each record taken at most once and only under its ceremony.
 */
final class InMemoryPasskeyChallengeStore implements PasskeyChallengeStoreInterface
{
    /** @var array<string, string> */
    private array $records = [];

    public function put(string $ceremony, string $challengeKey, string $options, int $ttlSeconds): void
    {
        $this->records[$ceremony . "\0" . $challengeKey] = $options;
    }

    public function take(string $ceremony, string $challengeKey): ?string
    {
        $key = $ceremony . "\0" . $challengeKey;
        $options = $this->records[$key] ?? null;
        unset($this->records[$key]);

        return $options;
    }
}
