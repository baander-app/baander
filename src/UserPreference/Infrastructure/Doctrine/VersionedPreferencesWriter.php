<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure\Doctrine;

use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Exception\PreferenceVersionConflict;
use App\UserPreference\Application\Port\PreferenceWriterPortInterface;
use Doctrine\DBAL\Connection;

final class VersionedPreferencesWriter implements PreferenceWriterPortInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param array<string, mixed> $payload */
    public function saveForUser(string $preferenceType, Uuid $userId, array $payload, int $expectedVersion): int
    {
        $table = match ($preferenceType) {
            'audio' => 'audio_preferences',
            'player' => 'player_preferences',
            'layout' => 'layout_preferences',
            default => throw new \InvalidArgumentException('Unknown preference type.'),
        };
        if ($expectedVersion < 0) {
            throw new \InvalidArgumentException('The expected version must be nonnegative.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($table, $preferenceType, $userId, $payload, $expectedVersion): int {
            $parameters = [
                'user_id' => $userId->toString(),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ];

            if ($expectedVersion === 0) {
                // The unique user constraint also serializes simultaneous first saves.
                $parameters['id'] = Uuid::generate()->toString();
                $version = $connection->fetchOne(
                    "INSERT INTO {$table} (id, user_id, payload, version, created_at, updated_at)
                     VALUES (:id, :user_id, CAST(:payload AS jsonb), 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                     ON CONFLICT (user_id) DO NOTHING RETURNING version",
                    $parameters,
                );
            } else {
                $parameters['expected_version'] = $expectedVersion;
                // PostgreSQL rechecks this predicate after waiting for a concurrent writer.
                $version = $connection->fetchOne(
                    "UPDATE {$table} SET payload = CAST(:payload AS jsonb), version = version + 1, updated_at = CURRENT_TIMESTAMP
                     WHERE user_id = :user_id AND version = :expected_version RETURNING version",
                    $parameters,
                );
            }

            if ($version === false) {
                $currentVersion = $connection->fetchOne("SELECT version FROM {$table} WHERE user_id = :user_id", ['user_id' => $userId->toString()]);
                throw new PreferenceVersionConflict($currentVersion === false ? 0 : (int) $currentVersion);
            }

            $connection->executeStatement(
                'INSERT INTO preference_history (id, user_id, preference_type, version, payload, created_at)
                 VALUES (:id, :user_id, :preference_type, :version, CAST(:payload AS jsonb), CURRENT_TIMESTAMP)',
                [
                    'id' => Uuid::generate()->toString(),
                    'user_id' => $userId->toString(),
                    'preference_type' => $preferenceType,
                    'version' => (int) $version,
                    'payload' => $parameters['payload'],
                ],
            );

            return (int) $version;
        });
    }
}
