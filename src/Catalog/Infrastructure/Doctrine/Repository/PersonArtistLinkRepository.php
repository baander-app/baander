<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Application\Port\PersonArtistLinkPortInterface;
use App\Catalog\Domain\ValueObject\PersonIdentifierNamespace;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\PersonWriteLock;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class PersonArtistLinkRepository implements PersonArtistLinkPortInterface
{
    public function __construct(
        private Connection $connection,
        private PersonIdentityRepository $identities,
    ) {}

    public function needsReconciliation(Uuid $artistId, string $mbid, ?string $type): bool
    {
        if ($type !== null && strcasecmp($type, 'Person') !== 0) {
            return false;
        }

        // A link only identifies the recorded Artist MBID. A match can re-identify that Artist.
        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1 FROM person_artist_links
                WHERE artist_id = :artist AND (managed_by_operator OR artist_mbid = :mbid)
                SQL,
            ['artist' => $artistId->toString(), 'mbid' => $mbid],
        ) === false;
    }

    public function reconcile(Uuid $artistId, string $mbid, array $ids, array $profile): void
    {
        PersonWriteLock::shared($this->connection);
        $artist = $this->connection->fetchAssociative(
            'SELECT id, name, type, mbid FROM artists WHERE id = :id',
            ['id' => $artistId->toString()],
        );
        if ($artist === false || ($artist['type'] !== null && strcasecmp((string) $artist['type'], 'Person') !== 0)
            || strtolower((string) $artist['mbid']) !== $mbid) {
            return;
        }

        $link = $this->link($artistId->toString());
        if ($link !== false && (bool) $link['managed_by_operator'] && $link['person_id'] === null) {
            return;
        }
        $existing = $link !== false && $link['artist_mbid'] === $mbid && $link['person_id'] !== null
            ? (string) $link['person_id']
            : null;
        $person = $this->identities->resolve(
            [PersonIdentifierNamespace::MusicBrainzArtist->value => $mbid] + $ids,
            (string) (($profile['name'] ?? '') ?: $artist['name']),
            'musicbrainz',
            $existing,
        );
        if ($person !== null) {
            if ($link === false || !(bool) $link['managed_by_operator']) {
                $this->write($artistId->toString(), $person, $mbid, false);
            }
            $this->identities->fillProfile($person, $profile);
        }
    }

    public function write(string $artist, ?string $person, ?string $mbid, bool $byOperator): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO person_artist_links (artist_id, person_id, artist_mbid, managed_by_operator)
                VALUES (:artist, :person, :mbid, :operator)
                ON CONFLICT (artist_id) DO UPDATE SET person_id = EXCLUDED.person_id,
                    artist_mbid = EXCLUDED.artist_mbid, managed_by_operator = EXCLUDED.managed_by_operator
                SQL,
            ['artist' => $artist, 'person' => $person, 'mbid' => $mbid, 'operator' => $byOperator],
            ['operator' => ParameterType::BOOLEAN],
        );
    }

    /** Both Artists are already row-locked by the caller. */
    public function beforeArtistMerge(Uuid $source, Uuid $target): void
    {
        PersonWriteLock::shared($this->connection);
        $old = $this->activeLink($source->toString());
        if ($old === false) {
            return;
        }
        $new = $this->activeLink($target->toString());
        if ($new !== false && $old['person_id'] !== $new['person_id']) {
            throw new ConflictException('Reconcile the linked Person profiles before merging the Artists.');
        }
        $targetMbid = $this->connection->fetchOne('SELECT mbid FROM artists WHERE id = :id', ['id' => $target->toString()]);
        if ($new === false && $this->link($target->toString()) === false
            && ($targetMbid === null || $targetMbid === $old['artist_mbid'])) {
            $this->write($target->toString(), (string) $old['person_id'], is_string($targetMbid) ? strtolower($targetMbid) : null, (bool) $old['managed_by_operator']);
        }
    }

    /** @return array<string, mixed>|false */
    private function link(string $artist): array|false
    {
        return $this->connection->fetchAssociative('SELECT * FROM person_artist_links WHERE artist_id = :id', ['id' => $artist]);
    }

    /** @return array<string, mixed>|false */
    private function activeLink(string $artist): array|false
    {
        return $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT links.* FROM person_artist_links links JOIN artists artist ON artist.id = links.artist_id
                WHERE links.artist_id = :id AND links.person_id IS NOT NULL
                  AND lower(artist.mbid) IS NOT DISTINCT FROM links.artist_mbid
                  AND (artist.type IS NULL OR lower(artist.type) = 'person')
                SQL,
            ['id' => $artist],
        );
    }
}
