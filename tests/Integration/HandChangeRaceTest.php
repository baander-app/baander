<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\HandChangeRace;
use App\Tests\Fixtures\Catalog\MatchApplyRace;
use App\Tests\Fixtures\Catalog\MatchSelections;
use App\Tests\Fixtures\Catalog\RetagLockRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

/**
 * A change an admin makes by hand to a song's credits, or to a song's fields, takes the locks every
 * catalog writer takes, in their order: the import keys of the artist's name and MusicBrainz ID,
 * the artist's row, the song's row. It therefore waits for a match apply, an import or another
 * hand change that holds one of them, and then acts on what that writer committed. Each test holds
 * one side's transaction open in the test session while the other side runs in a child process,
 * waits until the child waits on a lock, then commits. The tables copy the migrated ones without
 * their PGroonga indexes or triggers; the foreign keys of credits are added back.
 */
final class HandChangeRaceTest extends TestCase
{
    /** The SQLSTATE of a NOWAIT lock another session holds. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    private Connection $writer;
    private Connection $observer;
    private string $schema;
    private string $tag;
    /** @var array{process: resource, output: resource, application: string}|null */
    private ?array $child = null;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        $this->tag = random_bytes(4) |> bin2hex(...);
        $this->schema = 'hand_change_race_' . (random_bytes(8) |> bin2hex(...));
        $this->observer = ArtistImportIdentityRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->observer->fetchOne('SHOW server_version'));
        // The reads after each lock see a competing commit only under READ COMMITTED.
        self::assertSame('read committed', $this->observer->fetchOne('SHOW default_transaction_isolation'));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        MatchApplyRace::copyTables($this->observer);

        $this->writer = ArtistImportIdentityRace::connect($this->schema);
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $this->writer->close();
        }
        if ($this->child !== null) {
            // The test failed while the child ran; its own failure is the one to report.
            try {
                ArtistImportIdentityRace::finish($this->child);
            } catch (\RuntimeException|\JsonException) {
            }
        }
        if (isset($this->observer)) {
            $this->observer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->observer->close();
        }
    }

    /**
     * An admin credits Post Malone as producer on the duet while a match applied to its album holds
     * its locks, the credits it set for the duet written and not yet committed. The add waits on an
     * import key of the artist, which the apply took before any row. Once the apply commits, the add
     * reads the song as the match left it, writes its credit and drops the mark that the match set
     * the credits, so the next retag replaces them with the credits its tags give. An add that read
     * the song before the apply committed would leave that mark on a hand-made credit.
     */
    public function testACreditAddedWhileAMatchAppliesWaitsAndThenDropsTheMarkTheMatchSet(): void
    {
        $seed = HandChangeRace::seed($this->observer, $this->tag, postMaloneHoldsId: true);
        $manager = ArtistImportIdentityRace::entityManager($this->writer);
        $lookup = MatchApplyRace::lookup($this->tag);
        $selection = MatchApplyRace::compare($manager, $lookup, $seed['albumPublicId']) |> MatchSelections::defaults(...);
        $markedByTheMatch = [];
        $waiting = null;
        $handler = MatchApplyRace::handler($manager, $lookup, function () use ($seed, &$markedByTheMatch, &$waiting): void {
            $markedByTheMatch = HandChangeRace::matchedFields($this->writer, $seed['duetSong']);
            $this->child = HandChangeRace::start($this->schema, 'add', $this->credit($seed['postMalonePublicId'], $seed['duetSongPublicId'], 'producer'));
            $waiting = RetagLockRace::awaitLockWait($this->observer, $this->child);
        });

        ApplyMatchCommand::manual(MatchItemType::Album, $seed['albumPublicId'], MatchApplyRace::RELEASE_ID, $selection) |> $handler(...);
        $add = $this->finishChild();

        self::assertContains('credits', $markedByTheMatch, 'The match set the duet\'s credits.');
        self::assertSame(['outcome' => 'changed'], $add);
        self::assertSame(
            [
                [MatchApplyRace::postMalone($this->tag), 'primary', MatchApplyRace::duet($this->tag)],
                [MatchApplyRace::lukeCombs($this->tag), 'featured', MatchApplyRace::lukeCombs($this->tag)],
                [MatchApplyRace::postMalone($this->tag), 'producer', MatchApplyRace::postMalone($this->tag)],
            ],
            MatchApplyRace::songCredits($this->observer, $seed['duetSong']),
        );
        self::assertNotContains('credits', HandChangeRace::matchedFields($this->observer, $seed['duetSong']), 'The credits are no longer the ones the match set.');
        self::assertSame('advisory', $waiting['waitsOn'] ?? null, 'The add waits on an import key, before it locks a row.');
    }

    /**
     * The admin's add holds its locks, the featured credit written. A match of the album, compared
     * before the add, waits on the artist's import key, then compares again under its locks: the
     * duet's credits are no longer the ones the admin of the match saw, so it writes nothing.
     */
    public function testAMatchAppliedWhileACreditIsAddedWaitsAndThenFindsTheCreditsChanged(): void
    {
        $seed = HandChangeRace::seed($this->observer, $this->tag, postMaloneHoldsId: true);
        $selection = MatchApplyRace::compare(ArtistImportIdentityRace::entityManager($this->writer), MatchApplyRace::lookup($this->tag), $seed['albumPublicId'])
            |> MatchSelections::defaults(...);
        $waiting = null;

        $add = HandChangeRace::change(
            ArtistImportIdentityRace::entityManager($this->writer),
            'add',
            $this->credit($seed['postMalonePublicId'], $seed['duetSongPublicId'], 'featured'),
            function () use ($seed, $selection, &$waiting): void {
                $this->child = MatchApplyRace::start($this->schema, 'apply', [
                    'tag' => $this->tag,
                    'albumPublicId' => $seed['albumPublicId'],
                    'selection' => MatchSelections::toArray($selection),
                ]);
                $waiting = RetagLockRace::awaitLockWait($this->observer, $this->child);
            },
        );
        $apply = $this->finishChild();

        self::assertSame(['outcome' => 'changed'], $add);
        self::assertSame('advisory', $waiting['waitsOn'] ?? null, 'The apply waits on the import key the add holds.');
        self::assertSame(['conflict' => 'match_stale'], $apply);
        self::assertSame(
            [
                [MatchApplyRace::duet($this->tag), 'primary', MatchApplyRace::duet($this->tag)],
                [MatchApplyRace::postMalone($this->tag), 'featured', MatchApplyRace::postMalone($this->tag)],
            ],
            MatchApplyRace::songCredits($this->observer, $seed['duetSong']),
        );
        self::assertNull($this->observer->fetchOne('SELECT release_mbid FROM albums WHERE id = ?', [$seed['album']]), 'The match wrote nothing.');
        self::assertSame([], HandChangeRace::matchedFields($this->observer, $seed['duetSong']));
    }

    /**
     * The test holds the song's row. The add waits for it, and by then holds what comes before a
     * song row in the lock order: the import keys of the artist's name and ID, and the artist's row
     * locked for update. An add that locked the song before the artist would wait here without the
     * artist's row, and could then wait for an apply that holds the artist and waits for the song.
     */
    public function testAnAddTakesTheArtistsImportKeysAndRowBeforeItWaitsForTheSongsRow(): void
    {
        $seed = HandChangeRace::seed($this->observer, $this->tag, postMaloneHoldsId: true);
        $this->writer->beginTransaction();
        $this->writer->executeQuery('SELECT id FROM songs WHERE id = ? FOR NO KEY UPDATE', [$seed['soloSong']])->free();

        $this->child = HandChangeRace::start($this->schema, 'add', $this->credit($seed['postMalonePublicId'], $seed['soloSongPublicId'], 'producer'));
        $waiting = RetagLockRace::awaitLockWait($this->observer, $this->child);

        self::assertNotSame('advisory', $waiting['waitsOn'], 'The add waits for the song\'s row, not for an import key.');
        self::assertSame(2, $this->advisoryLocksHeldBy($waiting['pid']), 'It took the artist\'s name and ID keys before any row.');
        self::assertTrue($this->isLocked('artists', $seed['postMalone'], 'FOR KEY SHARE'), 'It locked the artist\'s row for update before it waited for the song\'s.');
        $this->writer->commit();
        self::assertSame(['outcome' => 'changed'], $this->finishChild());
    }

    /**
     * The test holds the import key of the artist's name. The add waits for it and holds no row
     * yet: an import that holds the key and then writes the artist or the song never waits for the
     * add in turn.
     */
    public function testAnAddWaitsForTheArtistsNameKeyBeforeItLocksARow(): void
    {
        $seed = HandChangeRace::seed($this->observer, $this->tag, postMaloneHoldsId: true);
        $this->writer->beginTransaction();
        ImportIdentityLock::acquire($this->writer, [MatchApplyRace::postMalone($this->tag) |> ImportIdentityLock::artistNameKey(...)]);

        $this->child = HandChangeRace::start($this->schema, 'add', $this->credit($seed['postMalonePublicId'], $seed['soloSongPublicId'], 'producer'));
        $waiting = RetagLockRace::awaitLockWait($this->observer, $this->child);

        self::assertSame('advisory', $waiting['waitsOn'], 'The add waits for the name key.');
        self::assertSame(1, $this->advisoryLocksHeldBy($waiting['pid']), 'It holds the ID key, which sorts before the name key.');
        self::assertFalse($this->isLocked('artists', $seed['postMalone'], 'FOR UPDATE'), 'It waits for the key before it locks the artist\'s row.');
        self::assertFalse($this->isLocked('songs', $seed['soloSong'], 'FOR UPDATE'), 'Nor the song\'s row.');
        $this->writer->commit();
        self::assertSame(['outcome' => 'changed'], $this->finishChild());
    }

    /**
     * Another session deletes the artist without holding its import keys and has not committed. The
     * add waits for the artist's row, finds no artist once the delete commits, and answers "not
     * found" without writing a credit. An add that inserted the credit without locking the artist
     * would fail on the credit's foreign key inside its flush instead.
     */
    public function testAnArtistRemovedWhileTheAddWaitedForItsRowIsNotFound(): void
    {
        $seed = HandChangeRace::seed($this->observer, $this->tag, postMaloneHoldsId: true);
        $this->writer->beginTransaction();
        $this->writer->executeStatement('DELETE FROM artists WHERE id = ?', [$seed['duetArtist']]);

        $this->child = HandChangeRace::start($this->schema, 'add', $this->credit($seed['duetArtistPublicId'], $seed['soloSongPublicId'], 'featured'));
        $waiting = RetagLockRace::awaitLockWait($this->observer, $this->child);
        $this->writer->commit();
        $add = $this->finishChild();

        self::assertNotSame('advisory', $waiting['waitsOn'], 'No session holds the artist\'s keys: the add waits for the row.');
        self::assertSame(['notFound' => sprintf('Artist "%s" not found.', $seed['duetArtistPublicId'])], $add);
        self::assertSame(
            [[MatchApplyRace::postMalone($this->tag), 'primary', MatchApplyRace::postMalone($this->tag)]],
            MatchApplyRace::songCredits($this->observer, $seed['soloSong']),
        );
    }

    /**
     * The add holds its locks on an artist without a MusicBrainz ID. An import whose batch retags
     * the same song and names the artist with an ID waits on the artist's name key before it holds
     * the song's row. Once the add commits, the import gives the artist the ID. Without the key the
     * import would hold the song's row and wait for the artist's, which the add holds while it
     * waits for the song's: the database would end one of them.
     */
    public function testAnImportThatGivesTheArtistAnIdWaitsForAnAddThatTookTheNameKeyFirstAndBothWrite(): void
    {
        $seed = HandChangeRace::seed($this->observer, $this->tag, postMaloneHoldsId: false);
        $waiting = null;

        $add = HandChangeRace::change(
            ArtistImportIdentityRace::entityManager($this->writer),
            'add',
            $this->credit($seed['postMalonePublicId'], $seed['soloSongPublicId'], 'producer'),
            function () use ($seed, &$waiting): void {
                $this->child = HandChangeRace::start($this->schema, 'import', ['artists' => [$this->taggedPostMalone()], 'songId' => $seed['soloSong']]);
                $waiting = RetagLockRace::awaitLockWait($this->observer, $this->child);
            },
        );
        $import = $this->finishChild();

        self::assertSame(['outcome' => 'changed'], $add);
        self::assertSame('advisory', $waiting['waitsOn'] ?? null, 'The import waits on the name key the add holds.');
        self::assertSame(['artists' => [$seed['postMalone']]], $import, 'The import ended normally and resolved the stored artist.');
        self::assertSame(HandChangeRace::postMaloneId($this->tag), $this->observer->fetchOne('SELECT mbid FROM artists WHERE id = ?', [$seed['postMalone']]));
        self::assertSame(
            [
                [MatchApplyRace::postMalone($this->tag), 'primary', MatchApplyRace::postMalone($this->tag)],
                [MatchApplyRace::postMalone($this->tag), 'producer', MatchApplyRace::postMalone($this->tag)],
            ],
            MatchApplyRace::songCredits($this->observer, $seed['soloSong']),
        );
    }

    /**
     * The import holds the artist's keys and the song's row and has given the artist its ID. The add,
     * which read the artist without an ID, waits on the name key. Once the import commits, the add
     * finds the artist holds an ID whose key it did not take: it writes nothing and answers that it
     * should be made again, rather than take that key after its rows. Made again, it writes.
     */
    public function testAnAddBehindAnImportThatGaveTheArtistAnIdAnswersBusyAndWritesWhenMadeAgain(): void
    {
        $seed = HandChangeRace::seed($this->observer, $this->tag, postMaloneHoldsId: false);
        $identities = ArtistImportIdentityRace::identities([$this->taggedPostMalone()]);
        $credit = $this->credit($seed['postMalonePublicId'], $seed['soloSongPublicId'], 'producer');
        $this->writer->beginTransaction();
        RetagLockRace::songs($this->writer)->lockForRetag($identities, [], [new Uuid($seed['soloSong'])]);
        (ArtistImportIdentityRace::entityManager($this->writer) |> ArtistImportIdentityRace::resolver(...))->resolve($identities);

        $this->child = HandChangeRace::start($this->schema, 'add', $credit);
        $waiting = RetagLockRace::awaitLockWait($this->observer, $this->child);
        $this->writer->commit();
        $refused = $this->finishChild();

        self::assertSame('advisory', $waiting['waitsOn'], 'The add waits on the name key the import holds.');
        self::assertSame(['conflict' => 'credit_busy', 'deadlock' => false], $refused);
        self::assertSame(HandChangeRace::postMaloneId($this->tag), $this->observer->fetchOne('SELECT mbid FROM artists WHERE id = ?', [$seed['postMalone']]), 'The import wrote.');
        self::assertSame(
            [[MatchApplyRace::postMalone($this->tag), 'primary', MatchApplyRace::postMalone($this->tag)]],
            MatchApplyRace::songCredits($this->observer, $seed['soloSong']),
            'The refused add wrote nothing.',
        );

        $this->child = HandChangeRace::start($this->schema, 'add', $credit);
        self::assertSame(['outcome' => 'changed'], $this->finishChild());
        self::assertSame(
            [
                [MatchApplyRace::postMalone($this->tag), 'primary', MatchApplyRace::postMalone($this->tag)],
                [MatchApplyRace::postMalone($this->tag), 'producer', MatchApplyRace::postMalone($this->tag)],
            ],
            MatchApplyRace::songCredits($this->observer, $seed['soloSong']),
        );
    }

    /**
     * A role change that names no current role has resolved its artist and song and waits for the
     * artist's name key. Another session changes the artist's only credit on the song from primary
     * to producer and commits. The role change then reads the credit under its locks and changes
     * the producer credit; had it read the roles before its locks, it would look for a primary
     * credit that no longer exists.
     */
    public function testARoleChangeActsOnTheRoleAnotherSessionCommittedBeforeItsLocks(): void
    {
        $seed = HandChangeRace::seed($this->observer, $this->tag, postMaloneHoldsId: true);
        $this->writer->beginTransaction();
        ImportIdentityLock::acquire($this->writer, [MatchApplyRace::postMalone($this->tag) |> ImportIdentityLock::artistNameKey(...)]);

        $this->child = HandChangeRace::start($this->schema, 'role', $this->credit($seed['postMalonePublicId'], $seed['soloSongPublicId'], 'composer'));
        RetagLockRace::awaitLockWait($this->observer, $this->child);
        $this->writer->executeStatement("UPDATE artist_song SET role = 'producer' WHERE artist_id = ? AND song_id = ?", [$seed['postMalone'], $seed['soloSong']]);
        $this->writer->commit();
        $change = $this->finishChild();

        self::assertSame(['outcome' => 'changed'], $change);
        self::assertSame(
            [[MatchApplyRace::postMalone($this->tag), 'composer', MatchApplyRace::postMalone($this->tag)]],
            MatchApplyRace::songCredits($this->observer, $seed['soloSong']),
        );
    }

    /**
     * A match set the song's title and credits. An admin's add holds the song's row, the credits
     * mark dropped and not yet committed, when another admin edits the title. The edit waits for
     * the row, reads the song as the add left it, and saves it with the new title and without the
     * title's mark. An edit that saved the song it read before the row was free would write the
     * credits mark back.
     */
    public function testATitleEditThatWaitedForACreditChangeKeepsTheCreditsMarkDropped(): void
    {
        $seed = HandChangeRace::seed($this->observer, $this->tag, postMaloneHoldsId: true);
        $this->observer->executeStatement("UPDATE songs SET matched_fields = '[\"title\", \"credits\"]' WHERE id = ?", [$seed['soloSong']]);
        $waiting = null;

        $add = HandChangeRace::change(
            ArtistImportIdentityRace::entityManager($this->writer),
            'add',
            $this->credit($seed['postMalonePublicId'], $seed['soloSongPublicId'], 'producer'),
            function () use ($seed, &$waiting): void {
                $this->child = HandChangeRace::start($this->schema, 'edit', ['song' => $seed['soloSongPublicId'], 'title' => 'Pour Me A Drink (Live)']);
                $waiting = RetagLockRace::awaitLockWait($this->observer, $this->child);
            },
        );
        $edit = $this->finishChild();

        self::assertSame(['outcome' => 'changed'], $add);
        self::assertNotSame('advisory', $waiting['waitsOn'] ?? 'advisory', 'The edit waits for the song\'s row.');
        self::assertSame(['outcome' => 'changed'], $edit);
        self::assertSame('Pour Me A Drink (Live)', $this->observer->fetchOne('SELECT title FROM songs WHERE id = ?', [$seed['soloSong']]));
        self::assertSame([], HandChangeRace::matchedFields($this->observer, $seed['soloSong']), 'Neither the replaced title nor the hand-changed credits count as matched.');
    }

    /**
     * @return array{artist: string, target: 'song', targetId: string, role: string} the payload of a change to the artist's credit on the song
     */
    private function credit(string $artistPublicId, string $songPublicId, string $role): array
    {
        return ['artist' => $artistPublicId, 'target' => 'song', 'targetId' => $songPublicId, 'role' => $role];
    }

    /** @return array{string, list<string>} Post Malone as a file tags him: his name and his MusicBrainz ID */
    private function taggedPostMalone(): array
    {
        return [MatchApplyRace::postMalone($this->tag), [HandChangeRace::postMaloneId($this->tag)]];
    }

    /** @return array<string, mixed> what the second session printed once it ended */
    private function finishChild(): array
    {
        self::assertNotNull($this->child, 'The second session was started.');
        $child = $this->child;
        $this->child = null;

        return ArtistImportIdentityRace::finish($child, $this->observer);
    }

    /** Whether another session holds a lock on the row that a lock of $strength conflicts with. */
    private function isLocked(string $table, string $id, string $strength): bool
    {
        try {
            $this->observer->executeQuery(sprintf('SELECT id FROM %s WHERE id = ? %s NOWAIT', $table, $strength), [$id])->free();
        } catch (DriverException $e) {
            self::assertSame(self::LOCK_NOT_AVAILABLE, $e->getSQLState(), $e->getMessage());

            return true;
        }

        return false;
    }

    private function advisoryLocksHeldBy(int $pid): int
    {
        return (int) $this->observer->fetchOne(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND granted AND pid = ?",
            [$pid],
        );
    }
}
