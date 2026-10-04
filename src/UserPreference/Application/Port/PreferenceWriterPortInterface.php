<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface PreferenceWriterPortInterface
{
    /**
     * Atomically save preferences and their history, requiring the stored version (0 when absent).
     *
     * @param array<string, mixed> $payload
     */
    public function saveForUser(string $preferenceType, Uuid $userId, array $payload, int $expectedVersion): int;
}
