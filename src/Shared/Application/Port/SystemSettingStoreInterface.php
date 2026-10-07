<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Raw storage of explicit server-wide setting values. A key without a row
 * follows its definition's default. Reads bypass any ORM identity map, so a
 * long-running worker sees a change made by another process.
 */
interface SystemSettingStoreInterface
{
    /**
     * Every stored value, decoded from JSON, keyed by setting key.
     *
     * @return array<string, mixed>
     */
    public function all(): array;

    /**
     * The stored value, or null when the key has no row.
     */
    public function find(string $key): mixed;

    /**
     * Upserts every value in one transaction.
     *
     * @param array<string, bool|int|string> $values
     */
    public function save(array $values): void;

    /**
     * Removes the explicit value; removing an unset key succeeds.
     */
    public function delete(string $key): void;
}
