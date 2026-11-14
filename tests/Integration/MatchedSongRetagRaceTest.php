<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Command\MusicBrainzMatch\MatchSelection;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\MatchApplyRace;
use App\Tests\Fixtures\Catalog\MatchSelections;
use App\Tests\Fixtures\Catalog\RetagLockRace;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * A retag of a song and a match applied to it at the same time take their locks in one order, so
 * the later one waits for the earlier and then sees what it wrote: a retag that waited reads the
 * credits the match set and that the match set them, and keeps them; an apply that waited finds the
 * title the retag wrote, which the admin did not see, and writes nothing. Each test holds one side's
 * transaction open in the test session while the other side runs in a child process, waits until
 * the child waits on a lock, then commits. The tables copy the migrated ones without their
 * PGroonga indexes or triggers; the foreign keys of credits are added back.
 */
final class MatchedSongRetagRaceTest extends TestCase
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
        $this->schema = 'matched_retag_' . (random_bytes(8) |> bin2hex(...));
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
     * A retag whose file still names "Post Malone, Luke Combs" without IDs starts while the apply
     * holds its locks. Once the apply commits, the retag reads the credits the match set, and that
     * the match set them, which is what makes it keep them rather than credit its tag text again.
     */
    public function testARetagThatWaitedForTheApplyReadsTheCreditsTheMatchSetAndThatItSetThem(): void
    {
        $seed = MatchApplyRace::seed($this->observer, $this->tag);
        $manager = ArtistImportIdentityRace::entityManager($this->writer);
        $lookup = MatchApplyRace::lookup($this->tag);
        $selection = MatchApplyRace::compare($manager, $lookup, $seed['albumPublicId']) |> MatchSelections::defaults(...);
        $handler = MatchApplyRace::handler($manager, $lookup, function () use ($seed): void {
            $this->child = MatchApplyRace::start($this->schema, 'retag', ['artists' => [[MatchApplyRace::duet($this->tag), []]], 'songId' => $seed['duetSong']]);
            RetagLockRace::awaitLockWait($this->observer, $this->child);
        });

        ApplyMatchCommand::manual(MatchItemType::Album, $seed['albumPublicId'], MatchApplyRace::RELEASE_ID, $selection) |> $handler(...);
        self::assertNotNull($this->child);
        $retag = ArtistImportIdentityRace::finish($this->child, $this->observer);
        $this->child = null;

        self::assertContains('credits', $retag['matchedFields'] ?? [], 'The retag keeps credits a match set.');
        self::assertSame(
            [[MatchApplyRace::postMalone($this->tag), 'primary', MatchApplyRace::duet($this->tag)], [MatchApplyRace::lukeCombs($this->tag), 'featured', MatchApplyRace::lukeCombs($this->tag)]],
            $retag['credits'] ?? null,
        );
    }

    /**
     * The retag holds its locks of the duet and has written the title its new tags carry. The apply,
     * compared before the retag and with the duet's title row ticked, waits for the retag, then finds
     * the title it would replace is no longer the one the admin saw and writes nothing. An apply that
     * kept the song as it read it before its locks would pass that check and overwrite the retag.
     */
    public function testAnApplyThatWaitedForARetagFindsTheTitleTheRetagWroteAndWritesNothing(): void
    {
        $seed = MatchApplyRace::seed($this->observer, $this->tag);
        $this->observer->executeStatement("UPDATE songs SET title = 'Guy For That (Live)' WHERE id = ?", [$seed['duetSong']]);
        $comparison = MatchApplyRace::compare(ArtistImportIdentityRace::entityManager($this->writer), MatchApplyRace::lookup($this->tag), $seed['albumPublicId']);
        $shown = MatchSelections::defaults($comparison);
        $duetPublicId = (string) $this->observer->fetchOne('SELECT public_id FROM songs WHERE id = ?', [$seed['duetSong']]);
        $selection = new MatchSelection(
            [...$shown->fields, new SeenField(MatchField::Title, 'Guy For That (Live)', $duetPublicId)],
            $shown->credits,
            $shown->artistActions,
            $shown->keptArtists,
        );

        $this->writer->beginTransaction();
        RetagLockRace::songs($this->writer)->lockForRetag(
            ArtistImportIdentityRace::identities([[MatchApplyRace::duet($this->tag), []]]),
            [],
            [new Uuid($seed['duetSong'])],
        );
        $this->writer->executeStatement("UPDATE songs SET title = 'Guy For That (Remastered)', hash = 'retagged', size = 2000000 WHERE id = ?", [$seed['duetSong']]);
        $this->child = MatchApplyRace::start($this->schema, 'apply', [
            'tag' => $this->tag,
            'albumPublicId' => $seed['albumPublicId'],
            'selection' => MatchSelections::toArray($selection),
        ]);
        RetagLockRace::awaitLockWait($this->observer, $this->child);
        $this->writer->commit();
        $apply = ArtistImportIdentityRace::finish($this->child, $this->observer);
        $this->child = null;

        self::assertSame(['conflict' => 'match_stale'], $apply);
        self::assertSame(
            ['title' => 'Guy For That (Remastered)', 'hash' => 'retagged', 'mbid' => null],
            $this->observer->fetchAssociative('SELECT title, hash, mbid FROM songs WHERE id = ?', [$seed['duetSong']]),
        );
        self::assertSame(
            [[MatchApplyRace::duet($this->tag), 'primary', MatchApplyRace::duet($this->tag)]],
            MatchApplyRace::songCredits($this->observer, $seed['duetSong']),
            'The apply wrote no credit either.',
        );
    }
}
