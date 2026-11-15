<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Command\MusicBrainzMatch\MatchSelection;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenCredits;
use App\Catalog\Application\Service\MusicBrainzMatch\AutomaticMatchOutcome;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\MatchApplyRace;
use App\Tests\Fixtures\Catalog\MatchSelections;
use App\Tests\Fixtures\Catalog\QueueRunRace;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * A run of the album match queue reads a matched album, asks MusicBrainz for its release and only
 * then takes the apply's locks. Another writer that commits while MusicBrainz is asked meets no
 * lock of the run, so nothing waits: each test lets that writer commit from inside the release
 * lookup, between the matcher's read and the apply's locks. The run then writes nothing, the album
 * is matched again later, and that later run acts on the songs as the writer left them. The matcher
 * and the apply are the production ones. The tables copy the migrated ones without their PGroonga
 * indexes or triggers; the foreign keys of credits are added back.
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
}
