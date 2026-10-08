<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Repository;

use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Infrastructure\Doctrine\JsonSettingValue;
use Doctrine\DBAL\Connection;

/**
 * Reads every value with a fresh query rather than through the ORM identity map, so a
 * long-running worker sees a value another process changed (values decide email language).
 *
 * Uses the default connection the entity manager uses, so a call inside a caller's
 * transaction commits or rolls back with it.
 */
final class SystemSettingRepository implements SystemSettingStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function all(): array
    {
        $values = [];
        foreach ($this->connection->fetchAllKeyValue('SELECT key, value FROM system_settings ORDER BY key') as $key => $json) {
            $values[(string) $key] = JsonSettingValue::decode($json, 'system setting');
        }

        return $values;
    }

    public function find(string $key): mixed
    {
        $json = $this->connection->fetchOne('SELECT value FROM system_settings WHERE key = :key', ['key' => $key]);

        return $json === false ? null : JsonSettingValue::decode($json, 'system setting');
    }

    public function save(array $values): void
    {
        if ($values === []) {
            return;
        }

        // One statement, so every value is written or none is.
        $rows = [];
        $parameters = [];
        foreach ($values as $key => $value) {
            $index = count($rows);
            $rows[] = sprintf('(:key_%1$d, CAST(:value_%1$d AS jsonb))', $index);
            $parameters['key_' . $index] = (string) $key;
            $parameters['value_' . $index] = JsonSettingValue::encode($value);
        }

        $this->connection->executeStatement(
            'INSERT INTO system_settings (key, value) VALUES ' . implode(', ', $rows)
            . ' ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()',
            $parameters,
        );
    }

    public function delete(string $key): void
    {
        $this->connection->executeStatement('DELETE FROM system_settings WHERE key = :key', ['key' => $key]);
    }
}
