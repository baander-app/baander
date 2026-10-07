<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Repository;

use App\Shared\Application\Port\SystemSettingStoreInterface;
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
            $values[(string) $key] = self::decode($json);
        }

        return $values;
    }

    public function find(string $key): mixed
    {
        $json = $this->connection->fetchOne('SELECT value FROM system_settings WHERE key = :key', ['key' => $key]);

        return $json === false ? null : self::decode($json);
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
            $parameters['value_' . $index] = json_encode($value, JSON_THROW_ON_ERROR);
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

    private static function decode(mixed $json): mixed
    {
        if (!is_string($json)) {
            throw new \UnexpectedValueException('A system setting value must be read as JSON text.');
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
