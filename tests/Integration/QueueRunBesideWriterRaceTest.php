<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchSelection;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenCredits;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticMatchOutcome;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\MatchApplyRace;
use App\Tests\Fixtures\Catalog\MatchSelections;
use App\Tests\Fixtures\Catalog\QueueRunRace;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\TraceableMessageBus;

/**
 * A run of the album match queue reads a matched album, asks MusicBrainz for its release and only
 * then takes the apply's locks. Another writer that commits while MusicBrainz is asked meets no
 * lock of the run, so nothing waits: each test lets that writer commit from inside the release
 * lookup, between the matcher's read and the apply's locks. The run then writes nothing, the album
 * is matched again later, and that later run acts on the songs as the writer left them.
 *
 * A manual apply has the same gap between its read of the album and its locks. An import that
 * commits a song and its match request there loses the request to the apply, which removes the
 * album's match state; the apply therefore asks for the album again once it committed, and the
 * run that follows writes the song. An import that commits after the apply removed the state
 * keeps its request.
 *
 * The matcher, the apply and the match requests are the production ones. The tables copy the
 * migrated ones without their PGroonga indexes or triggers; the foreign keys of credits are added
 * back.
 */
final class QueueRunBesideWriterRaceTest extends TestCase
{
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
        $this->schema = 'queue_run_race_' . (random_bytes(8) |> bin2hex(...));
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
     * The duet arrived after the album was matched. While the run asks MusicBrainz, an admin
     * applies the same release by hand and leaves the duet's credits unticked: the duet keeps its
     * combined artist and is compared from then on. The run, which read the duet as new, would
     * replace those credits with the release's.
     */
    public function testARunWritesNothingToASongAnAdminsApplyComparedWhileMusicBrainzWasAskedAndTheNextRunLeavesTheSongAlone(): void
    {
        $seed = MatchApplyRace::seed($this->observer, $this->tag);
        QueueRunRace::matchAlbum($this->observer, $seed['album'], [$seed['soloSong']]);
        $duetPublicId = (string) $this->observer->fetchOne('SELECT public_id FROM songs WHERE id = ?', [$seed['duetSong']]);
        $shown = MatchApplyRace::compare(ArtistImportIdentityRace::entityManager($this->observer), MatchApplyRace::lookup($this->tag), $seed['albumPublicId'])
            |> MatchSelections::defaults(...);
        self::assertContains($duetPublicId, array_column($shown->credits, 'songPublicId'), 'The admin saw the duet\'s credits ticked.');
        $selection = new MatchSelection(
            $shown->fields,
            array_filter($shown->credits, static fn (SeenCredits $credits): bool => $credits->songPublicId !== $duetPublicId) |> array_values(...),
        );
        $lookup = MatchApplyRace::lookup($this->tag);
        $admin = null;
        $afterAdmin = null;
        $lookup->duringReleaseLookup = function () use ($lookup, $seed, $selection, &$admin, &$afterAdmin): void {
            $lookup->duringReleaseLookup = null;
            $this->child = MatchApplyRace::start($this->schema, 'apply', [
                'tag' => $this->tag,
                'albumPublicId' => $seed['albumPublicId'],
                'selection' => MatchSelections::toArray($selection),
            ]);
            $admin = ArtistImportIdentityRace::finish($this->child, $this->observer);
            $this->child = null;
            $afterAdmin = QueueRunRace::rows($this->observer, $seed['album']);
        };
        $matcher = QueueRunRace::matcher(ArtistImportIdentityRace::entityManager($this->writer), $lookup);

        $run = $matcher->match(new Uuid($seed['album']));

        self::assertSame(['outcome' => 'applied', 'merged' => [], 'removed' => []], $admin, 'The admin\'s apply committed while MusicBrainz was asked.');
        self::assertNotNull($afterAdmin[$seed['duetSong']]['match_checked_at'] ?? null, 'The admin\'s apply compared the duet.');
        $asTheAdminLeftThem = [[MatchApplyRace::duet($this->tag), 'primary', MatchApplyRace::duet($this->tag)]];
        self::assertSame($asTheAdminLeftThem, MatchApplyRace::songCredits($this->observer, $seed['duetSong']), 'The duet keeps the credits the admin left unticked.');
        self::assertEquals(AutomaticMatchOutcome::retryLater(900), $run);
        self::assertSame($afterAdmin, QueueRunRace::rows($this->observer, $seed['album']), 'The run wrote nothing.');

        $next = $matcher->match(new Uuid($seed['album']));

        self::assertEquals(AutomaticMatchOutcome::matched(), $next);
        self::assertSame($asTheAdminLeftThem, MatchApplyRace::songCredits($this->observer, $seed['duetSong']));
        self::assertSame($afterAdmin[$seed['duetSong']], QueueRunRace::rows($this->observer, $seed['album'])[$seed['duetSong']], 'The next run finds no new song and writes nothing to the duet.');
    }

    /**
     * The scan has stored the album's first song only when an admin applies the release by hand.
     * While the apply asks MusicBrainz, an import commits the second song and, automatic matching
     * being on, its request for the album. The apply compared the first song alone, and it removes
     * the album's match state and with it that request: the second song would stay uncompared with
     * nothing asking for the album.
     */
    public function testAManualApplyAsksForTheAlbumAgainWhenAnImportAddedASongWhileMusicBrainzWasAskedAndTheNextRunWritesOnlyThatSong(): void
    {
        $seed = MatchApplyRace::seed($this->observer, $this->tag);
        $this->observer->executeStatement('DELETE FROM songs WHERE id = ?', [$seed['soloSong']]);
        $album = new Uuid($seed['album']);
        $selection = MatchApplyRace::compare(ArtistImportIdentityRace::entityManager($this->observer), MatchApplyRace::lookup($this->tag), $seed['albumPublicId'])
            |> MatchSelections::defaults(...);
        $lookup = MatchApplyRace::lookup($this->tag);
        $imported = null;
        $requestOfTheImport = null;
        $lookup->duringReleaseLookup = function () use ($lookup, $seed, $album, &$imported, &$requestOfTheImport): void {
            $lookup->duringReleaseLookup = null;
            $imported = MatchApplyRace::insertSong($this->observer, $seed['album'], 'Pour Me A Drink', 2, 201.5, [$seed['postMalone'], MatchApplyRace::postMalone($this->tag)]);
            QueueRunRace::requester(ArtistImportIdentityRace::entityManager($this->observer), true, new MessageBus())->requestAfterImport($album);
            $requestOfTheImport = $this->matchState($seed['album']);
        };
        $manager = ArtistImportIdentityRace::entityManager($this->writer);
        $runs = new TraceableMessageBus(new MessageBus());
        $runsBeforeTheCommit = null;
        $handler = MatchApplyRace::handler(
            $manager,
            $lookup,
            function () use ($runs, &$runsBeforeTheCommit): void {
                $runsBeforeTheCommit = self::queuedRuns($runs);
            },
            QueueRunRace::requester($manager, true, $runs),
        );

        $applied = ApplyMatchCommand::manual(MatchItemType::Album, $seed['albumPublicId'], MatchApplyRace::RELEASE_ID, $selection) |> $handler(...);

        self::assertTrue($applied->isApplied());
        self::assertIsString($imported, 'The import committed its song while MusicBrainz was asked.');
        self::assertSame(['state' => 'requested', 'source' => 'ingest'], $requestOfTheImport, 'The import asked for the album before the apply took its locks.');
        self::assertSame([], $runsBeforeTheCommit, 'The apply asks for no match before it committed.');
        $afterApply = QueueRunRace::rows($this->observer, $seed['album']);
        self::assertSame(MatchApplyRace::RELEASE_ID, $afterApply[$seed['album']]['release_mbid'] ?? null);
        self::assertNotNull($afterApply[$seed['duetSong']]['match_checked_at'] ?? null, 'The apply compared the song it read.');
        self::assertSame(['mbid' => null, 'match_checked_at' => null], array_intersect_key($afterApply[$imported], ['mbid' => 0, 'match_checked_at' => 0]), 'No match has compared the imported song.');
        self::assertSame(['state' => 'requested', 'source' => 'ingest'], $this->matchState($seed['album']), 'The album waits for a match again, as after an import.');
        self::assertSame([300_000], self::queuedRuns($runs), 'One queue run is on its way, due after the settling delay.');

        $next = QueueRunRace::matcher(ArtistImportIdentityRace::entityManager($this->writer), MatchApplyRace::lookup($this->tag))->match($album);

        self::assertEquals(AutomaticMatchOutcome::matched(), $next);
        $afterRun = QueueRunRace::rows($this->observer, $seed['album']);
        self::assertSame(MatchApplyRace::recordingId(2), $afterRun[$imported]['mbid'] ?? null, 'The run that follows pairs the imported song with the release\'s second track.');
        self::assertNotNull($afterRun[$imported]['match_checked_at'] ?? null);
        self::assertSame($afterApply[$seed['duetSong']], $afterRun[$seed['duetSong']], 'The run writes nothing to the song the apply compared.');
    }

    /**
     * The same apply beside an import of a song by an artist the release does not name, so the
     * import waits for none of the apply's locks. It commits the song and its request once the
     * apply has removed the album's match state and before the apply commits. The request did not
     * exist when the state was removed, so it stands after the apply.
     */
    public function testAnImportsRequestCommittedAfterAManualApplyRemovedTheMatchStateAndBeforeItCommittedStandsAfterTheApply(): void
    {
        $seed = MatchApplyRace::seed($this->observer, $this->tag);
        $this->observer->executeStatement('DELETE FROM songs WHERE id = ?', [$seed['soloSong']]);
        $guest = 'Morgan Wallen ' . $this->tag;
        $guestId = MatchApplyRace::insertArtist($this->observer, $guest);
        // A wait for a lock of the apply would never end: the apply goes on only once the import returned.
        $this->observer->executeStatement("SET lock_timeout = '10s'");
        $selection = MatchApplyRace::compare(ArtistImportIdentityRace::entityManager($this->observer), MatchApplyRace::lookup($this->tag), $seed['albumPublicId'])
            |> MatchSelections::defaults(...);
        $imported = null;
        $seenByTheImport = null;
        $whileTheApplyHoldsItsLocks = function () use ($seed, $guest, $guestId, &$imported, &$seenByTheImport): void {
            $imported = MatchApplyRace::insertSong($this->observer, $seed['album'], 'I Had Some Help', 3, 178.0, [$guestId, $guest]);
            QueueRunRace::requester(ArtistImportIdentityRace::entityManager($this->observer), true, new MessageBus())->requestAfterImport(new Uuid($seed['album']));
            $seenByTheImport = [
                'release' => $this->observer->fetchOne('SELECT release_mbid FROM albums WHERE id = ?', [$seed['album']]),
                'request' => $this->matchState($seed['album']),
            ];
        };
        $handler = MatchApplyRace::handler(ArtistImportIdentityRace::entityManager($this->writer), MatchApplyRace::lookup($this->tag), $whileTheApplyHoldsItsLocks);

        $applied = ApplyMatchCommand::manual(MatchItemType::Album, $seed['albumPublicId'], MatchApplyRace::RELEASE_ID, $selection) |> $handler(...);

        self::assertTrue($applied->isApplied());
        self::assertSame(
            ['release' => null, 'request' => ['state' => 'requested', 'source' => 'ingest']],
            $seenByTheImport,
            'The import committed its request while the apply had written everything and committed nothing.',
        );
        $afterApply = QueueRunRace::rows($this->observer, $seed['album']);
        self::assertSame(MatchApplyRace::RELEASE_ID, $afterApply[$seed['album']]['release_mbid'] ?? null);
        self::assertIsString($imported);
        self::assertNull($afterApply[$imported]['match_checked_at'] ?? null, 'No match has compared the imported song.');
        self::assertSame(['state' => 'requested', 'source' => 'ingest'], $this->matchState($seed['album']), 'The album still waits for the match the import asked for.');
    }

    /**
     * Both songs arrived after the album was matched, and each is within the pairing tolerance of
     * either track of the release, so its tagged number alone says which track it is. While the run
     * asks MusicBrainz, a retag swaps the two numbers. The run, which paired the songs by their old
     * numbers, would give each the recording and credits of the other's track.
     */
    public function testARunWritesNothingWhenARetagSwappedTwoNewSongsNumbersWhileMusicBrainzWasAskedAndTheNextRunPairsThemByTheirNewNumbers(): void
    {
        $seed = MatchApplyRace::seed($this->observer, $this->tag);
        QueueRunRace::matchAlbum($this->observer, $seed['album'], []);
        $this->observer->executeStatement('UPDATE songs SET length = 182.0 WHERE id = ?', [$seed['soloSong']]);
        $lookup = QueueRunRace::lookupOfLikeLengths($this->tag);
        $afterRetag = null;
        $lookup->duringReleaseLookup = function () use ($lookup, $seed, &$afterRetag): void {
            $lookup->duringReleaseLookup = null;
            $this->observer->executeStatement(
                'UPDATE songs SET track = CASE WHEN id = ? THEN 2 ELSE 1 END WHERE id IN (?, ?)',
                [$seed['duetSong'], $seed['duetSong'], $seed['soloSong']],
            );
            $afterRetag = QueueRunRace::rows($this->observer, $seed['album']);
        };
        $matcher = QueueRunRace::matcher(ArtistImportIdentityRace::entityManager($this->writer), $lookup);
        $recordings = fn (): array => array_map(
            fn (string $songId): mixed => $this->observer->fetchOne('SELECT mbid FROM songs WHERE id = ?', [$songId]),
            ['first' => $seed['duetSong'], 'second' => $seed['soloSong']],
        );

        $run = $matcher->match(new Uuid($seed['album']));

        self::assertSame([2, 1], [$afterRetag[$seed['duetSong']]['track'] ?? null, $afterRetag[$seed['soloSong']]['track'] ?? null], 'The retag committed while MusicBrainz was asked.');
        self::assertSame(['first' => null, 'second' => null], $recordings(), 'Neither song takes the recording of the track it paired with before the retag.');
        self::assertEquals(AutomaticMatchOutcome::retryLater(900), $run);
        self::assertSame($afterRetag, QueueRunRace::rows($this->observer, $seed['album']), 'The run wrote nothing.');

        $next = $matcher->match(new Uuid($seed['album']));

        self::assertEquals(AutomaticMatchOutcome::matched(), $next);
        self::assertSame(['first' => MatchApplyRace::recordingId(2), 'second' => MatchApplyRace::recordingId(1)], $recordings(), 'Each song takes the recording of the track at its new number.');
        self::assertSame(
            [[MatchApplyRace::postMalone($this->tag), 'primary', MatchApplyRace::duet($this->tag)]],
            MatchApplyRace::songCredits($this->observer, $seed['duetSong']),
            'The song now tagged as the second track takes that track\'s credit and keeps its own credited name.',
        );
        self::assertSame(
            [[MatchApplyRace::postMalone($this->tag), 'primary', MatchApplyRace::postMalone($this->tag)], [MatchApplyRace::lukeCombs($this->tag), 'featured', MatchApplyRace::lukeCombs($this->tag)]],
            MatchApplyRace::songCredits($this->observer, $seed['soloSong']),
            'The song now tagged as the first track takes the duet\'s credits.',
        );
    }

    /** @return list<int|null> the delay in milliseconds of each album match queue run dispatched on the bus */
    private static function queuedRuns(TraceableMessageBus $bus): array
    {
        $runs = array_filter($bus->getDispatchedMessages(), static fn (array $dispatched): bool => $dispatched['message'] instanceof DrainAlbumMatchesMessage);

        return array_map(
            static function (array $run): ?int {
                $delay = array_find($run['stamps'], static fn (StampInterface $stamp): bool => $stamp instanceof DelayStamp);

                return $delay instanceof DelayStamp ? $delay->getDelay() : null;
            },
            array_values($runs),
        );
    }

    /** @return array{state: string, source: string}|null the album's match state as stored, when it has one */
    private function matchState(string $albumId): ?array
    {
        $state = $this->observer->fetchAssociative('SELECT state, source FROM album_match_states WHERE album_id = ?', [$albumId]);

        return $state === false ? null : ['state' => (string) $state['state'], 'source' => (string) $state['source']];
    }
}
