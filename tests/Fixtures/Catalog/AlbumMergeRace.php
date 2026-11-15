<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Activity\Infrastructure\Doctrine\AlbumActivityMerge;
use App\Catalog\Application\Port\AlbumMatchRequestInterface;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Infrastructure\AlbumMergeService;
use App\Favorites\Infrastructure\Doctrine\AlbumFavoriteMerge;
use App\Media\Infrastructure\Storage\FlysystemStorage;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Psr\Log\NullLogger;

/**
 * The production album merge over a disposable schema, shared by AlbumMergeRaceTest and the child
 * process it runs (album-merge.php) as a second PostgreSQL session. The catalog services and their
 * transaction port come from {@see MatchApplyRace}; connections, entity managers and the child's
 * result from {@see ArtistImportIdentityRace}.
 */
final class AlbumMergeRace
{
    /**
     * Copies the tables a merge reads or writes, as {@see MatchApplyRace::copyTables()} does, and adds
     * back the foreign key of songs to their album: an insert into an album the merge holds waits
     * on it, and the database deletes a deleted album's songs through it.
     */
    public static function copyTables(Connection $connection): void
    {
        MatchApplyRace::copyTables($connection);
        $connection->executeStatement('ALTER TABLE songs ADD CONSTRAINT fk_songs_album_id FOREIGN KEY (album_id) REFERENCES albums (id) ON DELETE CASCADE');
    }

    /** The directory the merge's cover discarder deletes cover files from. */
    public static function storage(): string
    {
        return sys_get_temp_dir() . '/baander-album-merge-race';
    }

    /**
     * The merge over the manager. $beforeCommit runs inside the merge's transaction once it wrote
     * everything, while it holds every lock, before the commit. It requests no match of the target,
     * as while automatic matching is off.
     */
    public static function service(EntityManager $manager, ?Closure $beforeCommit = null): AlbumMergeService
    {
        $services = MatchApplyRace::services($manager, $beforeCommit);

        return new AlbumMergeService(
            $services['albums'],
            $services['songs'],
            self::noMatchRequests(),
            $services['transaction'],
            new CoverImageDiscarder($services['images'], new FlysystemStorage(self::storage()), new NullLogger()),
            new AlbumFavoriteMerge($manager),
            new AlbumActivityMerge($manager),
            new NullLogger(),
        );
    }

    /**
     * Merges the source into the target and reports how it ended.
     *
     * @return array{merged: string}|array{notFound: string}|array{conflict: string|null}
     */
    public static function merge(EntityManager $manager, string $targetId, string $sourceId, ?Closure $beforeCommit = null): array
    {
        try {
            $merged = self::service($manager, $beforeCommit)->mergeAlbums(Uuid::fromString($targetId), Uuid::fromString($sourceId));
        } catch (NotFoundException $notFound) {
            return ['notFound' => $notFound->getMessage()];
        } catch (ConflictException $conflict) {
            return ['conflict' => $conflict->details['reason'] ?? null];
        }

        return ['merged' => $merged->getId()->toString()];
    }

    /**
     * Two albums of one library: the target "Abbey Road" with "Come Together", and the source
     * "Abbey Road (Remaster)" with a cover, "Something" and a copy of the target's "Come Together"
     * from the same file. The cover's file is stored under {@see storage()}.
     *
     * @return array{library: string, target: string, source: string, kept: string, moved: string, duplicate: string, cover: string, coverFile: string}
     */
    public static function seed(Connection $connection, string $tag): array
    {
        $library = (new Uuid())->toString();
        $connection->executeStatement(
            "INSERT INTO libraries (id, public_id, name, slug, path, type, created_at, updated_at) VALUES (?, ?, 'Rock', ?, ?, 'music', now(), now())",
            [$library, (new PublicId())->toString(), 'rock-' . $tag, '/music/' . $tag],
        );
        $target = self::insertAlbum($connection, $library, 'Abbey Road');
        $source = self::insertAlbum($connection, $library, 'Abbey Road (Remaster)');
        $cover = (new Uuid())->toString();
        $coverPath = 'images/album/' . $tag . '/cover.jpg';
        $connection->executeStatement(
            "INSERT INTO images (id, public_id, path, extension, mime_type, size, width, height, imageable_type, album_id, created_at, updated_at) VALUES (?, ?, ?, 'jpg', 'image/jpeg', 5, 1, 1, 'album', ?, now(), now())",
            [$cover, (new PublicId())->toString(), $coverPath, $source],
        );
        $connection->executeStatement('UPDATE albums SET cover_image_id = ? WHERE id = ?', [$cover, $source]);
        $coverFile = self::storage() . '/' . $coverPath;
        if (!is_dir(dirname($coverFile)) && !mkdir(dirname($coverFile), 0o777, true) && !is_dir(dirname($coverFile))) {
            throw new \RuntimeException('Cannot create the cover directory.');
        }
        file_put_contents($coverFile, 'cover');

        return [
            'library' => $library,
            'target' => $target,
            'source' => $source,
            'kept' => self::insertSong($connection, $target, 'Come Together', 'come-together-' . $tag),
            'moved' => self::insertSong($connection, $source, 'Something', 'something-' . $tag),
            'duplicate' => self::insertSong($connection, $source, 'Come Together', 'come-together-' . $tag),
            'cover' => $cover,
            'coverFile' => $coverFile,
        ];
    }

    /** Stores an album of the library; returns its ID. */
    public static function insertAlbum(Connection $connection, string $library, string $title): string
    {
        $id = (new Uuid())->toString();
        $connection->executeStatement(
            "INSERT INTO albums (id, public_id, library_id, title, type, created_at, updated_at) VALUES (?, ?, ?, ?, 'album', now(), now())",
            [$id, (new PublicId())->toString(), $library, $title],
        );

        return $id;
    }

    /** Stores a song of the album from a file with this hash, as an import commits it; returns its ID. */
    public static function insertSong(Connection $connection, string $album, string $title, string $hash): string
    {
        $id = (new Uuid())->toString();
        $connection->executeStatement(
            "INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, length, track, disc, hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1000000, 'audio/flac', 180.0, 1, 1, ?, clock_timestamp(), clock_timestamp())",
            [$id, (new PublicId())->toString(), $album, $title, '/music/' . $id . '.flac', $hash],
        );

        return $id;
    }

    /**
     * Stores the user's favorite of the album, as Favorites stores one: by the album's public ID,
     * created at $createdAt. Returns the favorite's ID.
     */
    public static function favorite(Connection $connection, string $user, string $album, string $createdAt = '2026-11-01 10:00:00.123456+00'): string
    {
        $id = (new Uuid())->toString();
        $connection->executeStatement(
            "INSERT INTO user_favorites (id, public_id, user_id, entity_type, entity_public_id, created_at, updated_at) SELECT ?, ?, ?, 'album', public_id, ?, ? FROM albums WHERE id = ?",
            [$id, (new PublicId())->toString(), $user, $createdAt, $createdAt, $album],
        );

        return $id;
    }

    /** Stores the user's plays of the song on the album, as the player records them. Returns the activity's ID. */
    public static function play(Connection $connection, string $user, string $song, string $album): string
    {
        $id = (new Uuid())->toString();
        $connection->executeStatement(
            "INSERT INTO media_activities (id, public_id, user_id, activity_type, song_id, album_id, play_count, love, last_played_at, created_at, updated_at) VALUES (?, ?, ?, 'play', ?, ?, 3, false, now(), now(), now())",
            [$id, (new PublicId())->toString(), $user, $song, $album],
        );

        return $id;
    }

    /**
     * Starts the child session. "merge" {"target", "source"} merges the source into the target and
     * prints what {@see merge()} returns; "insert" {"album"} stores a song of the album in a
     * transaction of its own, as an import does, and prints {"inserted": ID}, or {"sqlstate": "..."}
     * when the insert fails.
     *
     * @param 'merge'|'insert'     $mode
     * @param array<string, mixed> $payload
     *
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, string $mode, array $payload): array
    {
        $application = 'album_merge_' . (random_bytes(6) |> bin2hex(...));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/album-merge.php', $schema, $application, $mode, json_encode($payload, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** Match requests that request nothing, as the production ones while automatic matching is off. */
    private static function noMatchRequests(): AlbumMatchRequestInterface
    {
        return new class () implements AlbumMatchRequestInterface {
            public function requestAfterImport(Uuid ...$albumIds): void
            {
            }

            public function requestForAdmin(Uuid $albumId): void
            {
                throw new \LogicException('A merge requests a match as an import does, never as an admin.');
            }
        };
    }
}
