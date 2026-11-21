<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Application\Command\Person\ManagePersonCommand;
use App\Catalog\Application\Port\PersonAdministrationPortInterface;
use App\Catalog\Domain\ValueObject\PersonDate;
use App\Catalog\Domain\ValueObject\PersonIdentifierNamespace;
use App\Catalog\Infrastructure\Doctrine\Entity\PersonEntity;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\PersonWriteLock;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class PersonAdministrationRepository implements PersonAdministrationPortInterface
{
    private const array PROFILE_FIELDS = [
        'name', 'image_id', 'profile_url', 'biography', 'aliases', 'birth_date',
        'death_date', 'is_deceased', 'birthplace', 'sex', 'gender',
    ];

    public function __construct(
        private Connection $connection,
        private TransactionPortInterface $transaction,
        private PersonIdentityRepository $identities,
        private PersonArtistLinkRepository $artistLinks,
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
    ) {}

    public function execute(ManagePersonCommand $command): array
    {
        if ($command->action === 'conflicts') {
            return ['conflicts' => $this->conflicts()];
        }

        $result = $this->transaction->run(function () use ($command): array {
            // Artist writers already hold their rows before taking the Person gate.
            $artist = in_array($command->action, ['link-artist', 'unlink-artist'], true)
                ? $this->lockArtist($command->value)
                : null;
            PersonWriteLock::exclusive($this->connection);

            return match ($command->action) {
                'create' => $this->create($command->profile),
                'release-field' => $this->release($this->person($command->person), $command->value),
                'edit' => $this->edit($this->person($command->person), $command->profile),
                'add-id', 'remove-id' => $this->changeIdentifier($this->person($command->person), $command),
                'link-artist', 'unlink-artist' => $this->changeArtistLink($this->person($command->person), $artist, $command->action === 'link-artist'),
                'merge' => $this->merge($this->person($command->person), $this->person($command->value), $command->preferTarget),
                default => throw new InvalidInputException('Unknown Person operation.'),
            };
        });
        $this->logger->info('Person administration committed.', [
            'actor' => 'cli',
            'operation' => $command->action,
            'person' => $result['publicId'],
        ]);

        return $result;
    }

    /** @param array<string, mixed> $profile
     *  @return array{publicId: string}
     */
    private function create(array $profile): array
    {
        $name = $profile['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new InvalidInputException('A person needs a display name.');
        }
        $id = $this->identities->create($name);
        $row = $this->connection->fetchAssociative('SELECT * FROM people WHERE id = :id', ['id' => $id]);
        assert($row !== false);

        return $this->edit($row, $profile);
    }

    /** @param array<string, mixed> $person
     *  @param array<string, mixed> $profile
     *  @return array{publicId: string}
     */
    private function edit(array $person, array $profile): array
    {
        $this->validateProfile($profile);
        $values = PersonIdentityRepository::profileValues($profile);
        if (array_key_exists('is_deceased', $profile) && $profile['is_deceased'] === false) {
            $values['is_deceased'] = false;
        }
        $combined = array_replace($person, $values);
        if ($combined['death_date'] !== null && !(bool) $combined['is_deceased']) {
            throw new InvalidInputException('Clear the death date before marking the person as living.');
        }
        $overrides = array_fill_keys(array_keys($profile), true);
        if (($values['death_date'] ?? null) !== null) {
            $overrides['is_deceased'] = true;
        }

        $this->updateProfile((string) $person['id'], $values, self::overrides($person) + $overrides);

        return ['publicId' => (string) $person['public_id']];
    }

    /** @param array<string, mixed> $person
     *  @return array{publicId: string}
     */
    private function release(array $person, ?string $field): array
    {
        if (!in_array($field, self::PROFILE_FIELDS, true)) {
            throw new InvalidInputException('Specify a profile field to release.');
        }
        $overrides = self::overrides($person);
        unset($overrides[$field]);
        $personId = (string) $person['id'];
        $this->updateProfile($personId, [], $overrides);
        $this->connection->executeStatement(
            "UPDATE person_external_ids SET profile_fetched_at = NULL WHERE person_id = :person AND namespace = 'tmdb_person'",
            ['person' => $personId],
        );

        return ['publicId' => (string) $person['public_id']];
    }

    /** @param array<string, mixed> $profile */
    private function validateProfile(array $profile): void
    {
        foreach ($profile as $key => $value) {
            if (!in_array($key, self::PROFILE_FIELDS, true)) {
                throw new InvalidInputException('Unknown profile field: ' . $key);
            }
            $valid = match ($key) {
                'name' => is_string($value) && trim($value) !== '',
                'aliases' => is_array($value) && array_is_list($value) && array_all($value, is_string(...)),
                'is_deceased' => is_bool($value),
                'birth_date', 'death_date' => $value === null || (is_string($value) && PersonDate::parse($value) !== null),
                default => $value === null || is_string($value),
            };
            if (!$valid) {
                throw new InvalidInputException('Invalid value for ' . $key);
            }
        }
        if (is_string($profile['image_id'] ?? null)) {
            try {
                $id = Uuid::fromString($profile['image_id']);
            } catch (\InvalidArgumentException) {
                throw new InvalidInputException('The managed image ID must be a UUID.');
            }
            if ($this->connection->fetchOne('SELECT 1 FROM images WHERE id = :id', ['id' => $id->toString()]) === false) {
                throw new NotFoundException('Managed image not found.');
            }
        }
        if (is_string($profile['profile_url'] ?? null) && PersonIdentityRepository::profileValues($profile)['profile_url'] === null) {
            throw new InvalidInputException('Use a TMDB portrait URL or the ID of a managed image.');
        }
    }

    /** @param array<string, mixed> $person
     *  @return array{publicId: string}
     */
    private function changeIdentifier(array $person, ManagePersonCommand $command): array
    {
        $namespace = PersonIdentifierNamespace::tryFrom($command->namespace ?? '');
        $value = $namespace?->normalize($command->value ?? '');
        if ($namespace === null || $value === null) {
            throw new InvalidInputException('Specify a supported namespace and a valid external identifier.');
        }
        $id = (string) $person['id'];
        $owner = $this->identities->owner($namespace->value, $value);
        if ($owner !== null && $owner !== $id) {
            throw new ConflictException('Another Person owns this identifier.');
        }

        $key = ['namespace' => $namespace->value, 'external_id' => $value];
        if ($command->action === 'add-id') {
            $this->identities->attach($id, $namespace->value, $value, 'operator');
            $this->connection->delete('person_identifier_blocks', $key);
        } elseif ($owner === $id) {
            $this->connection->delete('person_external_ids', $key);
            $this->connection->executeStatement(
                'INSERT INTO person_identifier_blocks (namespace, external_id) VALUES (:namespace, :external_id) ON CONFLICT DO NOTHING',
                $key,
            );
            if ($namespace === PersonIdentifierNamespace::MusicBrainzArtist) {
                $this->connection->executeStatement(
                    'UPDATE person_artist_links SET person_id = NULL, managed_by_operator = TRUE WHERE person_id = :person AND artist_mbid = :mbid',
                    ['person' => $id, 'mbid' => $value],
                );
            }
        }

        return ['publicId' => (string) $person['public_id']];
    }

    /** @return array<string, mixed> */
    private function lockArtist(?string $publicId): array
    {
        $artist = $this->connection->fetchAssociative(
            'SELECT id, mbid, type FROM artists WHERE public_id = :publicId FOR NO KEY UPDATE',
            ['publicId' => self::publicId($publicId)],
        );
        if ($artist === false) {
            throw new NotFoundException('Artist not found.');
        }

        return $artist;
    }

    /** @param array<string, mixed> $person
     *  @param array<string, mixed>|null $artist
     *  @return array{publicId: string}
     */
    private function changeArtistLink(array $person, ?array $artist, bool $link): array
    {
        assert($artist !== null);
        $personId = (string) $person['id'];
        $artistId = (string) $artist['id'];
        $mbid = PersonIdentifierNamespace::MusicBrainzArtist->normalize((string) $artist['mbid']);

        if ($link) {
            if ($artist['type'] !== null && strcasecmp((string) $artist['type'], 'Person') !== 0) {
                throw new InvalidInputException('Only an individual Artist can be associated with a Person.');
            }
            if ($mbid !== null) {
                $namespace = PersonIdentifierNamespace::MusicBrainzArtist->value;
                $owner = $this->identities->owner($namespace, $mbid);
                if ($owner !== null && $owner !== $personId) {
                    throw new ConflictException('Another Person owns the Artist MBID.');
                }
                $this->identities->attach($personId, $namespace, $mbid, 'operator');
                $this->connection->delete('person_identifier_blocks', ['namespace' => $namespace, 'external_id' => $mbid]);
            }
        } else {
            $owner = $this->connection->fetchOne(
                'SELECT person_id FROM person_artist_links WHERE artist_id = :artist',
                ['artist' => $artistId],
            );
            if (is_string($owner) && $owner !== $personId) {
                throw new ConflictException('This Artist is linked to another Person.');
            }
        }
        $this->artistLinks->write($artistId, $link ? $personId : null, $mbid, true);

        return ['publicId' => (string) $person['public_id']];
    }

    /** @param array<string, mixed> $source
     *  @param array<string, mixed> $target
     *  @return array{publicId: string}
     */
    private function merge(array $source, array $target, bool $preferTarget): array
    {
        $result = ['publicId' => (string) $target['public_id']];
        $ids = ['source' => (string) $source['id'], 'target' => (string) $target['id']];
        if ($ids['source'] === $ids['target']) {
            return $result;
        }

        $this->mergeProfiles($source, $target, $preferTarget);
        foreach (['movie', 'series', 'episode'] as $kind) {
            $table = $kind . '_person_credits';
            $key = $kind . '_id';
            $this->connection->executeStatement(
                <<<SQL
                    DELETE FROM {$table} duplicate
                    USING {$table} retained
                    WHERE duplicate.person_id = :source AND retained.person_id = :target
                      AND retained.{$key} = duplicate.{$key}
                      AND (retained.category, retained.role, retained.job, retained.character_name, retained.credited_name)
                          IS NOT DISTINCT FROM
                          (duplicate.category, duplicate.role, duplicate.job, duplicate.character_name, duplicate.credited_name)
                    SQL,
                $ids,
            );
            $this->connection->executeStatement("UPDATE {$table} SET person_id = :target WHERE person_id = :source", $ids);
        }
        foreach (['person_external_ids', 'person_artist_links', 'person_id_aliases'] as $table) {
            $this->connection->executeStatement("UPDATE {$table} SET person_id = :target WHERE person_id = :source", $ids);
        }
        $this->connection->insert('person_id_aliases', ['public_id' => $source['public_id'], 'person_id' => $ids['target']]);
        $sourceEntity = $this->entityManager->find(PersonEntity::class, Uuid::fromString($ids['source']));
        if ($sourceEntity !== null) {
            $this->entityManager->remove($sourceEntity);
            $this->entityManager->flush();
        }

        return $result;
    }

    /** @param array<string, mixed> $source
     *  @param array<string, mixed> $target
     */
    private function mergeProfiles(array $source, array $target, bool $preferTarget): void
    {
        $sourceOverrides = self::overrides($source);
        $targetOverrides = self::overrides($target);
        $values = [];
        foreach (self::PROFILE_FIELDS as $field) {
            if (isset($sourceOverrides[$field], $targetOverrides[$field]) && $source[$field] !== $target[$field] && !$preferTarget) {
                throw new ConflictException('Resolve contradictory profile values or explicitly prefer the survivor.', ['field' => $field]);
            }
            if (!isset($targetOverrides[$field]) && (isset($sourceOverrides[$field]) || $target[$field] === null)) {
                $values[$field] = $source[$field];
                if (in_array($field, ['birth_date', 'death_date'], true)) {
                    $precision = substr($field, 0, -4) . 'precision';
                    $values[$precision] = $source[$precision];
                }
            }
        }
        if (!isset($targetOverrides['aliases'])) {
            $aliases = array_merge(self::aliases($target), self::aliases($source), [(string) $source['name']]);
            $values['aliases'] = json_encode(array_values(array_unique(array_diff($aliases, [(string) $target['name']]))), JSON_THROW_ON_ERROR);
        }
        if ((bool) $source['is_deceased'] && !isset($targetOverrides['is_deceased'])) {
            $values['is_deceased'] = true;
        }
        $combined = array_replace($target, $values);
        if ($combined['death_date'] !== null && !(bool) $combined['is_deceased']) {
            throw new ConflictException('Resolve the death date and living status before merging these profiles.');
        }
        $this->updateProfile((string) $target['id'], $values, $targetOverrides + $sourceOverrides);
    }

    /** @param array<string, mixed> $values
     *  @param array<string, mixed> $overrides
     */
    private function updateProfile(string $person, array $values, array $overrides): void
    {
        $entity = $this->entityManager->find(PersonEntity::class, Uuid::fromString($person));
        if ($entity === null) {
            throw new NotFoundException('Person not found.');
        }
        $this->entityManager->refresh($entity);
        $imageId = $values['image_id'] ?? null;
        $image = is_string($imageId) ? $this->entityManager->find(ImageEntity::class, Uuid::fromString($imageId)) : null;
        $entity->applyProfile($values, $overrides, $image);
        $this->entityManager->flush();
    }

    /** @return array<string, mixed> */
    private function person(?string $publicId): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT p.* FROM people p
                WHERE p.public_id = :id OR p.id = (SELECT a.person_id FROM person_id_aliases a WHERE a.public_id = :id)
                SQL,
            ['id' => self::publicId($publicId)],
        );
        if ($row === false) {
            throw new NotFoundException('Person not found.');
        }

        return $row;
    }

    private static function publicId(?string $publicId): string
    {
        if ($publicId === null || preg_match(PublicId::REGEX, $publicId) !== 1) {
            throw new InvalidInputException('A valid public ID is required.');
        }

        return $publicId;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function overrides(array $row): array
    {
        return json_decode((string) $row['profile_overrides'], true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $row
     *  @return list<string>
     */
    private static function aliases(array $row): array
    {
        return json_decode((string) $row['aliases'], true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function conflicts(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT conflicts.claim_key AS key, conflicts.claims, conflicts.source,
                       conflicts.observed_at AS observed, jsonb_agg(DISTINCT people.public_id ORDER BY people.public_id) AS people
                FROM person_identity_conflicts conflicts
                CROSS JOIN LATERAL jsonb_each_text(conflicts.claims) claim(namespace, external_id)
                JOIN person_external_ids ids ON ids.namespace = claim.namespace AND ids.external_id = claim.external_id
                JOIN people ON people.id = ids.person_id
                GROUP BY conflicts.claim_key, conflicts.claims, conflicts.source, conflicts.observed_at
                HAVING count(DISTINCT people.id) > 1
                ORDER BY conflicts.observed_at DESC, conflicts.claim_key
                LIMIT 100
                SQL,
        );
        return array_map(static function (array $row): array {
            $row['claims'] = json_decode((string) $row['claims'], true, flags: JSON_THROW_ON_ERROR);
            $row['people'] = json_decode((string) $row['people'], true, flags: JSON_THROW_ON_ERROR);

            return $row;
        }, $rows);
    }
}
