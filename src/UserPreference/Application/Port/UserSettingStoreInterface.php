<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Raw storage of a user's explicit setting choices. A row exists only for an
 * explicit choice; deleting it makes the setting follow its default again.
 * Reads bypass any ORM identity map, so a long-running worker sees changes.
 */
interface UserSettingStoreInterface
{
    /**
     * Every stored choice of the user, decoded from JSON, keyed by setting key.
     *
     * @return array<string, mixed>
     */
    public function findAll(Uuid $userId): array;

    /**
     * The stored choice, or null when the user has none.
     */
    public function find(Uuid $userId, string $key): mixed;

    /**
     * Inserts or replaces the choice (last write wins).
     */
    public function save(Uuid $userId, string $key, bool|int|string $value): void;

    /**
     * Removes the choice; removing an absent choice succeeds.
     */
    public function delete(Uuid $userId, string $key): void;
}
