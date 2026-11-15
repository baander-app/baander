<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Application\Command\Artist\AddArtistCreditCommand;
use App\Catalog\Application\Command\Artist\ChangeArtistCreditRoleCommand;
use App\Catalog\Application\Command\Artist\CreditTarget;
use App\Catalog\Application\Command\Artist\RemoveArtistCreditCommand;
use App\Catalog\Application\Command\Song\UpdateSongCommand;
use App\Catalog\Application\CommandHandler\Artist\AddArtistCreditHandler;
use App\Catalog\Application\CommandHandler\Artist\ChangeArtistCreditRoleHandler;
use App\Catalog\Application\CommandHandler\Artist\RemoveArtistCreditHandler;
use App\Catalog\Application\CommandHandler\Song\UpdateSongHandler;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\TransactionDeadlockException;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;

/**
 * The production hand changes over a disposable schema, shared by HandChangeRaceTest and the child
 * process it runs (hand-change.php) as a second PostgreSQL session: the three artist credit use
 * cases and the song field edit, over the catalog services and the seed of {@see MatchApplyRace}.
 * Connections, entity managers and the child's result come from {@see ArtistImportIdentityRace}.
 */
final class HandChangeRace
{
    /**
     * The seed of {@see MatchApplyRace::seed()} with the public IDs a hand change names. With
     * $postMaloneHoldsId, Post Malone holds the MusicBrainz ID the release credits him under, so a
     * match applied to the album leaves his name and ID as they are.
     *
     * @return array{album: string, albumPublicId: string, duetSong: string, soloSong: string, otherSong: string, duetArtist: string, postMalone: string, duetSongPublicId: string, soloSongPublicId: string, duetArtistPublicId: string, postMalonePublicId: string}
     */
    public static function seed(Connection $connection, string $tag, bool $postMaloneHoldsId): array
    {
        $seed = MatchApplyRace::seed($connection, $tag);
        if ($postMaloneHoldsId) {
            $connection->executeStatement('UPDATE artists SET mbid = ? WHERE id = ?', [self::postMaloneId($tag), $seed['postMalone']]);
        }
        $publicId = static fn (string $table, string $id): string => (string) $connection->fetchOne(sprintf('SELECT public_id FROM %s WHERE id = ?', $table), [$id]);

        return [
            ...$seed,
            'duetSongPublicId' => $publicId('songs', $seed['duetSong']),
            'soloSongPublicId' => $publicId('songs', $seed['soloSong']),
            'duetArtistPublicId' => $publicId('artists', $seed['duetArtist']),
            'postMalonePublicId' => $publicId('artists', $seed['postMalone']),
        ];
    }

    /** The MusicBrainz artist ID the release credits Post Malone under in the test tagged $tag. */
    public static function postMaloneId(string $tag): string
    {
        return MatchApplyRace::postMalone($tag) |> MatchApplyRace::artistId(...);
    }

    /**
     * Makes one hand change through its production handler and reports how it ended. $beforeCommit
     * runs inside the change's transaction once it wrote everything, while it holds every lock,
     * before the commit.
     *
     * - "add" {"artist", "target", "targetId", "role"}, "remove" {"artist", "target", "targetId"} and
     *   "role" {"artist", "target", "targetId", "role", "currentRole"?} change the artist's credits
     *   on the song or album; "target" is "song" or "album" and every ID a public ID.
     * - "edit" {"song", "title"} gives the song another title.
     *
     * @param 'add'|'remove'|'role'|'edit' $mode
     * @param array<string, mixed>         $payload
     *
     * @return array{outcome: 'changed'}|array{notFound: string}|array{conflict: string|null, deadlock: bool} "deadlock" tells a
     *                                                                                                        change the database ended to break a deadlock from one refused for another reason
     */
    public static function change(EntityManager $manager, string $mode, array $payload, ?Closure $beforeCommit = null): array
    {
        $services = MatchApplyRace::services($manager, $beforeCommit);
        $ports = [$services['artists'], $services['songs'], $services['albums'], $services['merges'], $services['transaction']];
        try {
            match ($mode) {
                'add' => (new AddArtistCreditHandler(...$ports))(
                    new AddArtistCreditCommand($payload['artist'], CreditTarget::from($payload['target']), $payload['targetId'], $payload['role']),
                ),
                'remove' => (new RemoveArtistCreditHandler(...$ports))(
                    new RemoveArtistCreditCommand($payload['artist'], CreditTarget::from($payload['target']), $payload['targetId']),
                ),
                'role' => (new ChangeArtistCreditRoleHandler(...$ports))(
                    new ChangeArtistCreditRoleCommand($payload['artist'], CreditTarget::from($payload['target']), $payload['targetId'], $payload['role'], $payload['currentRole'] ?? null),
                ),
                'edit' => (new UpdateSongHandler($services['songs'], $services['transaction']))(new UpdateSongCommand($payload['song'], title: $payload['title'])),
            };
        } catch (NotFoundException $notFound) {
            return ['notFound' => $notFound->getMessage()];
        } catch (ConflictException $conflict) {
            return ['conflict' => $conflict->details['reason'] ?? null, 'deadlock' => $conflict->getPrevious() instanceof TransactionDeadlockException];
        }

        return ['outcome' => 'changed'];
    }

    /**
     * Starts the child session. "add", "remove", "role" and "edit" make the hand change of
     * {@see change()} and print what it returns. "import" {"artists": [[tagged name, MusicBrainz
     * IDs], ...], "songId"} takes a retag's locks of the song and the artists its tags name, resolves
     * those artists as an import does, which gives a same-name artist without an ID the tagged one,
     * and prints {"artists": [ID, ...]}.
     *
     * @param 'add'|'remove'|'role'|'edit'|'import' $mode
     * @param array<string, mixed>                  $payload
     *
     * @return array{process: resource, output: resource, application: string}
     */
    public static function start(string $schema, string $mode, array $payload): array
    {
        $application = 'hand_change_' . (random_bytes(6) |> bin2hex(...));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create the contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/hand-change.php', $schema, $application, $mode, json_encode($payload, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start the contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @return list<string> the fields of the song a match set, `credits` among them when it set the credits */
    public static function matchedFields(Connection $connection, string $songId): array
    {
        $fields = json_decode((string) $connection->fetchOne('SELECT matched_fields FROM songs WHERE id = ?', [$songId]), true, flags: JSON_THROW_ON_ERROR);

        return is_array($fields) ? array_map(strval(...), $fields) |> array_values(...) : [];
    }
}
