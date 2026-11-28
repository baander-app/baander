<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Application\Port\PersonCreditInput;
use App\Catalog\Application\Port\PersonIdentityPortInterface;
use App\Catalog\Domain\Model\Person;
use App\Catalog\Domain\ValueObject\PersonDate;
use App\Catalog\Domain\ValueObject\PersonIdentifierNamespace;
use App\Catalog\Infrastructure\Doctrine\Entity\PersonEntity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\PersonWriteLock;
use App\Shared\Application\Port\TransactionPortInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PersonIdentityRepository implements PersonIdentityPortInterface
{
    public function __construct(
        private Connection $connection,
        private TransactionPortInterface $transaction,
        private EntityManagerInterface $entityManager,
    ) {}

    public static function identifierLock(string $namespace, string $value): string
    {
        return 'person-external:' . $namespace . ':' . $value;
    }

    /**
     * @param list<PersonCreditInput> $credits
     * @return array<int, string>|null Person UUID by input index; null means a blocked or conflicting identity.
     */
    public function resolveCredits(array $credits): ?array
    {
        PersonWriteLock::shared($this->connection);
        $claims = [];
        $normalized = [];
        foreach ($credits as $index => $credit) {
            $normalized[$index] = self::normalize($credit->identifiers);
            foreach ($normalized[$index] as $namespace => $value) {
                $key = self::identifierLock($namespace, $value);
                $claims[$key] = ['namespace' => $namespace, 'external_id' => $value];
            }
        }
        ImportIdentityLock::acquire($this->connection, array_keys($claims));

        $known = [];
        $blocked = [];
        if ($claims !== []) {
            // DBAL projects the locked identity pairs without loading a managed entity per claim.
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT input.namespace, input.external_id, ids.person_id, blocks.namespace IS NOT NULL AS blocked
                    FROM jsonb_to_recordset(CAST(:claims AS jsonb)) AS input(namespace text, external_id text)
                    LEFT JOIN person_external_ids ids USING (namespace, external_id)
                    LEFT JOIN person_identifier_blocks blocks USING (namespace, external_id)
                    SQL,
                ['claims' => json_encode(array_values($claims), JSON_THROW_ON_ERROR)],
            );
            foreach ($rows as $row) {
                $key = self::identifierLock($row['namespace'], $row['external_id']);
                if ($row['person_id'] !== null) {
                    $known[$key] = $row['person_id'];
                }
                if ($row['blocked']) {
                    $blocked[$key] = true;
                }
            }
        }

        $owners = [];
        $attachments = [];
        $created = false;
        foreach ($credits as $index => $credit) {
            $ids = $normalized[$index];
            $candidates = [];
            foreach ($ids as $namespace => $value) {
                $key = self::identifierLock($namespace, $value);
                if (isset($blocked[$key])) {
                    $this->flushCreditIdentities($created, $attachments);
                    return null;
                }
                if (isset($known[$key])) {
                    $candidates[] = $known[$key];
                }
            }
            $candidates = array_values(array_unique($candidates));
            sort($candidates);
            if ($ids === [] || count($candidates) > 1) {
                $this->flushCreditIdentities($created, $attachments);
                if (count($candidates) > 1) {
                    $this->recordConflict($ids, $candidates, $credit->source);
                }
                return null;
            }

            $owner = $candidates[0] ?? null;
            if ($owner === null) {
                $entity = PersonEntity::fromPerson(Person::create($credit->name), self::portraitUrl($credit->profileUrl));
                $this->entityManager->persist($entity);
                $owner = $entity->getId()->toString();
                $created = true;
            }
            foreach ($ids as $namespace => $value) {
                $key = self::identifierLock($namespace, $value);
                if (!isset($known[$key])) {
                    $attachments[] = ['person_id' => $owner, 'namespace' => $namespace, 'external_id' => $value, 'source' => $credit->source];
                    $known[$key] = $owner;
                }
            }
            $owners[$index] = $owner;
        }
        $this->flushCreditIdentities($created, $attachments);

        return $owners;
    }

    /** @param list<array{person_id: string, namespace: string, external_id: string, source: string}> $attachments */
    private function flushCreditIdentities(bool $created, array $attachments): void
    {
        if ($created) {
            $this->entityManager->flush();
        }
        if ($attachments === []) {
            return;
        }
        // PostgreSQL recordset insertion batches claims while preserving first-claim provenance.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO person_external_ids (person_id, namespace, external_id, source)
                SELECT person_id, namespace, external_id, source
                FROM jsonb_to_recordset(CAST(:rows AS jsonb)) AS input(
                    person_id uuid, namespace text, external_id text, source text
                )
                ON CONFLICT (namespace, external_id) DO NOTHING
                SQL,
            ['rows' => json_encode($attachments, JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * @param array<string, string> $ids
     * @param array<string, mixed> $profile
     */
    public function enrichTmdbPerson(string $tmdbId, array $ids, array $profile): void
    {
        $namespace = PersonIdentifierNamespace::TmdbPerson->value;
        $this->transaction->run(function () use ($namespace, $tmdbId, $ids, $profile): void {
            // The pending-list entry can have been merged, removed or blocked during I/O.
            PersonWriteLock::shared($this->connection);
            if ($this->owner($namespace, $tmdbId) === null) {
                return;
            }

            $name = $profile['name'] ?? null;
            $person = $this->resolve([$namespace => $tmdbId] + $ids, is_string($name) ? $name : '', 'tmdb');
            if ($person === null) {
                return;
            }

            $this->fillProfile($person, $profile);
            // Store the database clock with the completed refresh and leave unresolved claims pending.
            $this->connection->executeStatement(
                'UPDATE person_external_ids SET profile_fetched_at = CURRENT_TIMESTAMP, profile_claimed_until = NULL WHERE namespace = :namespace AND external_id = :id',
                ['namespace' => $namespace, 'id' => $tmdbId],
            );
        });
    }

    public function pendingTmdbPeople(int $limit, ?string $after = null): array
    {
        if ($limit < 1) {
            return [];
        }

        return $this->connection->fetchFirstColumn(
            <<<'SQL'
                WITH pending AS (
                    SELECT namespace, external_id
                    FROM person_external_ids
                    WHERE namespace = 'tmdb_person' AND profile_fetched_at IS NULL
                      AND external_id > :after
                      AND (profile_claimed_until IS NULL OR profile_claimed_until <= CURRENT_TIMESTAMP)
                    ORDER BY external_id
                    LIMIT :limit
                    FOR UPDATE SKIP LOCKED
                ), claimed AS (
                    UPDATE person_external_ids ids
                    SET profile_claimed_until = CURRENT_TIMESTAMP + INTERVAL '5 minutes'
                    FROM pending
                    WHERE ids.namespace = pending.namespace AND ids.external_id = pending.external_id
                    RETURNING ids.external_id
                )
                SELECT external_id FROM claimed ORDER BY external_id
                SQL,
            ['after' => $after ?? '', 'limit' => min($limit, 5)],
            ['limit' => ParameterType::INTEGER],
        );
    }

    /**
     * The caller owns the transaction. Every provider identity is locked before the first lookup.
     *
     * @param array<string, string> $ids
     */
    public function resolve(
        array $ids,
        string $name,
        string $source,
        ?string $establishedOwner = null,
        ?string $profileUrl = null,
    ): ?string {
        PersonWriteLock::shared($this->connection);
        $ids = self::normalize($ids);
        if ($ids === []) {
            return null;
        }

        $keys = [];
        foreach ($ids as $namespace => $value) {
            $keys[] = self::identifierLock($namespace, $value);
        }
        ImportIdentityLock::acquire($this->connection, $keys);

        $owners = $establishedOwner === null ? [] : [$establishedOwner];
        foreach ($ids as $namespace => $value) {
            if ($this->isBlocked($namespace, $value)) {
                return null;
            }
            $owner = $this->owner($namespace, $value);
            if ($owner !== null) {
                $owners[] = $owner;
            }
        }
        $owners = array_values(array_unique($owners));
        sort($owners);
        if (count($owners) > 1) {
            $this->recordConflict($ids, $owners, $source);
            return null;
        }

        $owner = $owners[0] ?? $this->create($name, self::portraitUrl($profileUrl));
        foreach ($ids as $namespace => $value) {
            $this->attach($owner, $namespace, $value, $source);
        }

        return $owner;
    }

    /** @param array<string, string> $ids
     *  @return array<string, string>
     */
    private static function normalize(array $ids): array
    {
        $result = [];
        foreach ($ids as $namespace => $value) {
            $valid = PersonIdentifierNamespace::tryFrom($namespace)?->normalize($value);
            if ($valid !== null) {
                $result[$namespace] = $valid;
            }
        }
        ksort($result);

        return $result;
    }

    public function create(string $name, ?string $profileUrl = null): string
    {
        $entity = PersonEntity::fromPerson(Person::create($name), $profileUrl);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity->getId()->toString();
    }

    public function owner(string $namespace, string $value): ?string
    {
        $owner = $this->connection->fetchOne(
            'SELECT person_id FROM person_external_ids WHERE namespace = :namespace AND external_id = :id',
            ['namespace' => $namespace, 'id' => $value],
        );

        return is_string($owner) ? $owner : null;
    }

    public function isBlocked(string $namespace, string $value): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM person_identifier_blocks WHERE namespace = :namespace AND external_id = :id',
            ['namespace' => $namespace, 'id' => $value],
        ) !== false;
    }

    public function attach(string $person, string $namespace, string $value, string $source): void
    {
        // An already known external ID retains its original claim provenance.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO person_external_ids (person_id, namespace, external_id, source)
                VALUES (:person, :namespace, :id, :source)
                ON CONFLICT (namespace, external_id) DO NOTHING
                SQL,
            ['person' => $person, 'namespace' => $namespace, 'id' => $value, 'source' => $source],
        );
    }

    /**
     * @param array<string, string> $ids
     * @param list<string> $owners
     */
    public function recordConflict(array $ids, array $owners, string $source): void
    {
        $claims = json_encode($ids, JSON_THROW_ON_ERROR);
        $people = json_encode($owners, JSON_THROW_ON_ERROR);
        $key = hash('sha256', $claims . $people);
        // The same rejected claim can be observed by several workers or retried.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO person_identity_conflicts (claim_key, claims, owners, source)
                VALUES (:key, CAST(:claims AS jsonb), CAST(:owners AS jsonb), :source)
                ON CONFLICT (claim_key) DO UPDATE SET observed_at = CURRENT_TIMESTAMP
                SQL,
            ['key' => $key, 'claims' => $claims, 'owners' => $people, 'source' => $source],
        );
    }

    /** @param array<string, mixed> $profile */
    public function fillProfile(string $person, array $profile): void
    {
        $changes = self::profileValues($profile);
        $assignments = [];
        $parameters = ['person' => $person];
        // The guarded assignments preserve an administrator's explicit clear and a
        // value filled by another provider while this transaction was waiting.
        foreach ($changes as $column => $value) {
            if ($value === null || in_array($column, ['name', 'birth_precision', 'death_precision', 'image_id'], true)) {
                continue;
            }
            $condition = "NOT jsonb_exists(profile_overrides, '{$column}')";
            if ($column === 'death_date' || $column === 'is_deceased') {
                $condition .= " AND (NOT jsonb_exists(profile_overrides, 'is_deceased') OR is_deceased = TRUE)";
            }
            if ($column === 'aliases') {
                $condition .= " AND aliases = '[]'::jsonb";
                $expression = 'CAST(:aliases AS jsonb)';
            } elseif ($column === 'is_deceased') {
                $expression = 'TRUE';
            } else {
                $condition .= " AND {$column} IS NULL";
                $expression = in_array($column, ['birth_date', 'death_date'], true) ? 'CAST(:' . $column . ' AS date)' : ':' . $column;
            }
            $assignments[] = "{$column} = CASE WHEN {$condition} THEN {$expression} ELSE {$column} END";
            if ($column !== 'is_deceased') {
                $parameters[$column] = $value;
            }
            if (in_array($column, ['birth_date', 'death_date'], true)) {
                $precision = substr($column, 0, -4) . 'precision';
                $assignments[] = "{$precision} = CASE WHEN {$condition} THEN CAST(:{$precision} AS smallint) ELSE {$precision} END";
                $parameters[$precision] = $changes[$precision] ?? null;
            }
        }
        if ($assignments !== []) {
            $this->connection->executeStatement(
                'UPDATE people SET ' . implode(', ', $assignments) . ' WHERE id = :person',
                $parameters,
            );
        }
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, string|int|bool|null>
     */
    public static function profileValues(array $profile): array
    {
        $result = [];
        foreach (['name', 'profile_url', 'biography', 'birthplace', 'sex', 'gender', 'image_id'] as $key) {
            if (array_key_exists($key, $profile)) {
                $value = is_string($profile[$key]) ? trim($profile[$key]) : '';
                $result[$key] = $key === 'profile_url' ? self::portraitUrl($value) : ($value === '' ? null : $value);
            }
        }
        foreach (['birth', 'death'] as $field) {
            $key = $field . '_date';
            if (array_key_exists($key, $profile)) {
                $date = PersonDate::parse(is_string($profile[$key]) ? $profile[$key] : null);
                $result[$key] = $date?->firstDay->format('Y-m-d');
                $result[$field . '_precision'] = $date?->precision;
            }
        }
        if (array_key_exists('aliases', $profile)) {
            $aliases = is_array($profile['aliases']) ? $profile['aliases'] : [];
            $aliases = array_filter($aliases, static fn(mixed $alias): bool => is_string($alias) && trim($alias) !== '');
            $result['aliases'] = json_encode(array_map(trim(...), $aliases) |> array_unique(...) |> array_values(...), JSON_THROW_ON_ERROR);
        }
        if (($profile['is_deceased'] ?? false) === true || ($result['death_date'] ?? null) !== null) {
            $result['is_deceased'] = true;
        } elseif (array_key_exists('is_deceased', $profile)) {
            $result['is_deceased'] = null;
        }

        return $result;
    }

    private static function portraitUrl(?string $url): ?string
    {
        if ($url === null || preg_match('~^https://image\.tmdb\.org/t/p/[a-z0-9]+/[a-zA-Z0-9._-]+$~D', $url) !== 1) {
            return null;
        }

        return $url;
    }
}
