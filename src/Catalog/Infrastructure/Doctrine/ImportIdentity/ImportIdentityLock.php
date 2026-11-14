<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\ImportIdentity;

use App\Catalog\Domain\ValueObject\MusicbrainzId;
use Doctrine\DBAL\Connection;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Serializes the writers that find or create a catalog identity no unique key arbitrates, such
 * as an artist found by its name. Each key is a transaction-scoped advisory lock: it holds until
 * the caller's transaction ends, so a writer that waited for it reads what the holder committed.
 */
#[Exclude]
final class ImportIdentityLock
{
    /**
     * The seed of every identity lock: hashtextextended(key, seed). Its own seed keeps these
     * locks apart from the library import lock and the application's other advisory locks; a
     * hash collision only makes two identities wait for each other.
     */
    private const int SEED = 20261114120000;

    /**
     * Takes the lock of every key, waiting while another transaction holds one. Each key is taken
     * once and in byte order, so transactions that lock overlapping keys cannot deadlock. That
     * holds across calls in one transaction only when each call's keys sort after every key an
     * earlier call took: pass all keys at once, or lock in order of key prefix.
     *
     * @param list<string> $keys
     *
     * @throws LogicException outside a transaction, before taking any lock
     */
    public static function acquire(Connection $connection, array $keys): void
    {
        // Outside a transaction each lock would end with its own statement.
        if (!$connection->isTransactionActive()) {
            throw new LogicException('Take import identity locks inside the transaction that writes the identities.');
        }

        foreach (self::inLockOrder($keys) as $key) {
            // SQL because DQL has no advisory lock; one statement per key takes the keys in the
            // order sorted here.
            $connection->executeQuery(
                'SELECT pg_advisory_xact_lock(hashtextextended(:key, ' . self::SEED . '))',
                ['key' => $key],
            )->free();
        }
    }

    /** The key of the artists whose name equals the tagged name exactly. */
    public static function artistNameKey(string $name): string
    {
        return 'artist-name:' . $name;
    }

    /** The key of the artist that holds, or is about to take on, a MusicBrainz ID. */
    public static function artistMusicbrainzIdKey(MusicbrainzId $id): string
    {
        return 'artist-mbid:' . $id->toString();
    }

    /**
     * The key of the genre with a slug. Genres are unique by slug, and names such as "Hip-Hop"
     * and "Hip Hop" share one, so the slug is the key two imports would collide on.
     */
    public static function genreKey(string $slug): string
    {
        return 'genre:' . $slug;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private static function inLockOrder(array $keys): array
    {
        $keys = array_unique($keys, SORT_STRING);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
