<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\JsonSettingValue;
use App\UserPreference\Application\Port\UserSettingStoreInterface;
use Doctrine\DBAL\Connection;

/**
 * Reads every choice with a fresh query rather than through the ORM identity map, so a
 * long-running worker sees a choice another process changed.
 *
 * Uses the default connection the entity manager uses and opens no transaction of its own,
 * so a save inside a caller's transaction (such as registration) commits or rolls back with it.
 */
final class UserSettingRepository implements UserSettingStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function findAll(Uuid $userId): array
    {
        $choices = [];
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT key, value FROM user_settings WHERE user_id = :user_id ORDER BY key',
            ['user_id' => $userId->toString()],
        );
        foreach ($rows as $key => $json) {
            $choices[(string) $key] = JsonSettingValue::decode($json, 'user setting');
        }

        return $choices;
    }

    public function find(Uuid $userId, string $key): mixed
    {
        $json = $this->connection->fetchOne(
            'SELECT value FROM user_settings WHERE user_id = :user_id AND key = :key',
            ['user_id' => $userId->toString(), 'key' => $key],
        );

        return $json === false ? null : JsonSettingValue::decode($json, 'user setting');
    }

    public function save(Uuid $userId, string $key, bool|int|string $value): void
    {
        $this->connection->executeStatement(
            'INSERT INTO user_settings (user_id, key, value) VALUES (:user_id, :key, CAST(:value AS jsonb))
             ON CONFLICT (user_id, key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()',
            [
                'user_id' => $userId->toString(),
                'key' => $key,
                'value' => JsonSettingValue::encode($value),
            ],
        );
    }

    public function delete(Uuid $userId, string $key): void
    {
        $this->connection->executeStatement(
            'DELETE FROM user_settings WHERE user_id = :user_id AND key = :key',
            ['user_id' => $userId->toString(), 'key' => $key],
        );
    }
}
