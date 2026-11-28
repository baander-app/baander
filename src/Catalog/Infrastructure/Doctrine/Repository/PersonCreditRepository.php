<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Application\Port\PersonCreditInput;
use App\Catalog\Application\Port\PersonCreditPortInterface;
use App\Catalog\Application\Port\PersonCreditTargetInput;
use App\Catalog\Domain\ValueObject\PersonIdentifierNamespace;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\PersonWriteLock;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;

final readonly class PersonCreditRepository implements PersonCreditPortInterface
{
    public function __construct(
        private Connection $connection,
        private PersonIdentityRepository $identities,
    ) {}

    public function syncMovieCredits(Uuid $movieId, ?array $cast, ?array $crew, bool $forceUpdate = false): bool
    {
        return $this->syncCredits([new PersonCreditTargetInput('movie', $movieId, $cast, $crew, $forceUpdate)]);
    }

    public function syncTvCredits(string $kind, Uuid $targetId, ?array $cast, ?array $crew, bool $forceUpdate = false): bool
    {
        if (!in_array($kind, ['series', 'episode'], true)) {
            throw new InvalidArgumentException('Unknown television credit target.');
        }

        return $this->syncCredits([new PersonCreditTargetInput($kind, $targetId, $cast, $crew, $forceUpdate)]);
    }

    public function syncCredits(array $targets): bool
    {
        $keys = [];
        $prepared = [];
        foreach ($targets as $target) {
            $keys[] = 'person-work:' . $target->kind . ':' . $target->targetId->toString();
            $cast = self::validPartition($target->cast, 'cast');
            $crew = self::validPartition($target->crew, 'crew');
            $prepared[] = ['target' => $target, 'cast' => $cast, 'crew' => $crew];
            foreach ([...($cast ?? []), ...($crew ?? [])] as $credit) {
                foreach ($credit->identifiers as $namespace => $value) {
                    $keys[] = PersonIdentityRepository::identifierLock($namespace, $value);
                }
            }
        }
        PersonWriteLock::shared($this->connection);
        ImportIdentityLock::acquire($this->connection, $keys);

        $accepted = false;
        foreach ($prepared as $entry) {
            $target = $entry['target'];
            $changed = $this->sync($target->kind, $target->targetId, $entry['cast'], $entry['crew'], $target->forceUpdate);
            $accepted = $changed || $accepted;
        }

        return $accepted;
    }

    /**
     * @param 'movie'|'series'|'episode' $kind
     * @param list<PersonCreditInput>|null $cast
     * @param list<PersonCreditInput>|null $crew
     */
    private function sync(string $kind, Uuid $target, ?array $cast, ?array $crew, bool $force): bool
    {
        if ($cast === null && $crew === null) {
            return false;
        }

        $targetId = $target->toString();
        $state = $kind . '_person_credit_state';
        $key = $kind . '_id';
        $credits = $kind . '_person_credits';
        $flags = $this->connection->fetchAssociative(
            "SELECT * FROM {$state} WHERE {$key} = :target",
            ['target' => $targetId],
        );
        $castReady = (bool) ($flags['cast_initialized'] ?? false);
        $crewReady = (bool) ($flags['crew_initialized'] ?? false);
        $directorsReady = (bool) ($flags['directors_initialized'] ?? false);

        $accepted = false;
        if ($cast !== null && (!$castReady || $force) && $this->replace($credits, $key, $targetId, 'cast', $cast, false)) {
            $castReady = true;
            $accepted = true;
        }
        if ($crew !== null && (!$crewReady || $force) && $this->replace($credits, $key, $targetId, 'crew', $crew, $directorsReady && !$force)) {
            $crewReady = true;
            $directorsReady = true;
            $accepted = true;
        }
        if ($accepted) {
            $values = ['cast_initialized' => $castReady, 'crew_initialized' => $crewReady];
            if ($kind === 'movie') {
                $values['directors_initialized'] = $directorsReady;
            }
            // The advisory key serializes updates of the work's partition state.
            $types = array_fill_keys(array_keys($values), ParameterType::BOOLEAN);
            if ($flags === false) {
                $this->connection->insert($state, [$key => $targetId] + $values, $types);
            } else {
                $this->connection->update($state, $values, [$key => $targetId], $types);
            }
        }

        return $accepted;
    }

    /** @param list<PersonCreditInput> $incoming */
    private function replace(
        string $table,
        string $key,
        string $targetId,
        string $category,
        array $incoming,
        bool $keepDirectors,
    ): bool {
        $owners = $this->identities->resolveCredits($incoming);
        if ($owners === null) {
            return false;
        }

        $rows = [];
        foreach ($incoming as $index => $credit) {
            $personId = $owners[$index];
            $sourceKey = self::creditKey($credit, $personId);
            $rows[$sourceKey] ??= [
                'id' => Uuid::v7()->toString(),
                'person_id' => $personId,
                'category' => $category,
                'role' => $credit->role,
                'job' => $credit->job,
                'character_name' => $credit->characterName,
                'department' => $credit->department,
                'credited_name' => $credit->creditedName,
                'billing_order' => $credit->billingOrder,
                'provider_credit_id' => $credit->providerCreditId,
                'credit_key' => $sourceKey,
            ];
        }

        if ($keepDirectors) {
            $this->reconcileDirectorKeys($table, $key, $targetId, $rows);
        }

        if ($rows !== []) {
            // PostgreSQL bulk upsert preserves the IDs of credits still present on refresh.
            $this->connection->executeStatement(
                <<<SQL
                    INSERT INTO {$table} (
                        id, {$key}, person_id, category, role, job, character_name, department,
                        credited_name, billing_order, provider_credit_id, credit_key
                    )
                    SELECT id, :target, person_id, category, role, job, character_name, department,
                           credited_name, billing_order, provider_credit_id, credit_key
                    FROM jsonb_to_recordset(CAST(:rows AS jsonb)) AS input(
                        id uuid, person_id uuid, category text, role text, job text, character_name text,
                        department text, credited_name text, billing_order integer, provider_credit_id text,
                        credit_key text
                    )
                    ON CONFLICT ({$key}, credit_key) DO UPDATE SET
                        person_id = EXCLUDED.person_id, role = EXCLUDED.role, job = EXCLUDED.job,
                        character_name = EXCLUDED.character_name, department = EXCLUDED.department,
                        credited_name = EXCLUDED.credited_name, billing_order = EXCLUDED.billing_order,
                        provider_credit_id = EXCLUDED.provider_credit_id
                    SQL,
                ['target' => $targetId, 'rows' => json_encode(array_values($rows), JSON_THROW_ON_ERROR)],
            );
        }

        $delete = "DELETE FROM {$table} WHERE {$key} = :target AND category = :category";
        if ($keepDirectors) {
            $delete .= " AND role <> 'director'";
        }
        $params = ['target' => $targetId, 'category' => $category];
        $types = [];
        if ($rows !== []) {
            $delete .= ' AND credit_key NOT IN (:keys)';
            $params['keys'] = array_keys($rows);
            $types['keys'] = ArrayParameterType::STRING;
        }
        $this->connection->executeStatement($delete, $params, $types);

        return true;
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function reconcileDirectorKeys(string $table, string $key, string $targetId, array $rows): void
    {
        $migrated = $this->connection->fetchAllKeyValue(
            "SELECT person_id, credit_key FROM {$table} WHERE {$key} = :target AND role = 'director' AND credit_key LIKE 'director:%'",
            ['target' => $targetId],
        );
        foreach ($rows as $sourceKey => $row) {
            $person = $row['person_id'];
            if ($row['role'] !== 'director' || !isset($migrated[$person])) {
                continue;
            }
            // Re-key the retained director only while its migration key still matches.
            // The work lock serializes the matching provider upsert that follows.
            $this->connection->executeStatement(
                "UPDATE {$table} SET credit_key = :source WHERE {$key} = :target AND credit_key = :migrated",
                ['source' => $sourceKey, 'target' => $targetId, 'migrated' => $migrated[$person]],
            );
            unset($migrated[$person]);
        }
    }

    private static function creditKey(PersonCreditInput $credit, string $personId): string
    {
        if ($credit->providerCreditId !== null && $credit->providerCreditId !== '') {
            return $credit->category . ':' . $credit->source . ':' . $credit->providerCreditId;
        }

        return $credit->category . ':semantic:' . hash('sha256', json_encode([
            $personId,
            $credit->role,
            $credit->job,
            $credit->characterName,
            $credit->creditedName,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<PersonCreditInput>|null $incoming
     * @return list<PersonCreditInput>|null
     */
    private static function validPartition(?array $incoming, string $category): ?array
    {
        if ($incoming === null) {
            return null;
        }
        $valid = [];
        foreach ($incoming as $credit) {
            if (
                $credit->source === 'local'
                && array_intersect_key(
                    $credit->identifiers,
                    array_flip(['imdb_name', 'tmdb_person', 'tvdb_person']),
                ) === []
            ) {
                continue;
            }
            if (
                $credit->category !== $category
                || $credit->identifiers === []
                || !in_array($credit->source, ['tmdb', 'tvdb', 'local'], true)
                || trim($credit->name) === ''
                || trim($credit->job) === ''
                || trim($credit->role) === ''
            ) {
                return null;
            }
            foreach ($credit->identifiers as $namespace => $value) {
                if (PersonIdentifierNamespace::tryFrom($namespace)?->normalize($value) !== $value) {
                    return null;
                }
            }
            $valid[] = $credit;
        }

        return $valid;
    }
}
