<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Command\MusicBrainzMatch\ApplyMatchCommand;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchItemType;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\MatchApplyRace;
use App\Tests\Fixtures\Catalog\MatchSelections;
use App\Tests\Fixtures\Catalog\RetagLockRace;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * A match apply and an import that credits an artist the apply would merge away serialize on the
 * artist's import key, which the apply takes before it locks any row: the artist ends present and
 * credited, or absent and uncredited, never credited while deleted. Each test holds one side's
 * transaction open in the test session while the other side runs in a child process, waits until
 * the child waits on a lock, then commits. The tables copy the migrated ones without their
 * PGroonga indexes or triggers; the foreign keys of credits are added back.
 */
final class MusicBrainzMatchApplyRaceTest extends TestCase
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
        $this->schema = 'match_apply_race_' . (random_bytes(8) |> bin2hex(...));
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
     * The apply merges the duet's combined artist into Post Malone while an import credits an
     * artist of that name on another song. The import waits on the artist's name key, which the
     * apply took with every other key before it locked a row, and then finds the artist gone and
     * creates it anew. Without that key the import would find the artist the apply is deleting,
     * wait on its row instead, and fail on the credit's foreign key once the apply commits.
     */
    public function testAnImportCreditingAnArtistTheApplyMergesWaitsAndThenCreatesTheArtistAnew(): void
    {
        $seed = MatchApplyRace::seed($this->observer, $this->tag);
        $manager = ArtistImportIdentityRace::entityManager($this->writer);
        $lookup = MatchApplyRace::lookup($this->tag);
        $selection = MatchApplyRace::compare($manager, $lookup, $seed['albumPublicId']) |> MatchSelections::defaults(...);
        $handler = MatchApplyRace::handler($manager, $lookup, function () use ($seed): void {
            $this->child = MatchApplyRace::start($this->schema, 'import', ['name' => MatchApplyRace::duet($this->tag), 'songId' => $seed['otherSong']]);
            ArtistImportIdentityRace::awaitAdvisoryLockWait($this->observer, $this->child);
        });

        $result = ApplyMatchCommand::manual(MatchItemType::Album, $seed['albumPublicId'], MatchApplyRace::RELEASE_ID, $selection) |> $handler(...);
        self::assertNotNull($this->child);
        $import = ArtistImportIdentityRace::finish($this->child, $this->observer);
        $this->child = null;

        self::assertCount(1, $result->mergedArtists);
        self::assertArrayHasKey('artist', $import, 'The import credited its artist: ' . json_encode($import));
        self::assertNotSame($seed['duetArtist'], $import['artist'], 'The merged artist is gone; the import created a new one.');
        self::assertSame(
            [[MatchApplyRace::duet($this->tag), 'primary', MatchApplyRace::duet($this->tag)]],
            MatchApplyRace::songCredits($this->observer, $seed['otherSong']),
        );
        self::assertSame(
            [[MatchApplyRace::postMalone($this->tag), 'primary', MatchApplyRace::duet($this->tag)], [MatchApplyRace::lukeCombs($this->tag), 'featured', MatchApplyRace::lukeCombs($this->tag)]],
            MatchApplyRace::songCredits($this->observer, $seed['duetSong']),
        );
        self::assertFalse($this->observer->fetchOne('SELECT 1 FROM artists WHERE id = ?', [$seed['duetArtist']]));
    }

    /**
     * The import credits the combined artist on another song first and holds its transaction open.
     * The apply, compared before the import, waits on the artist's name key before it locks any row,
     * then reads the credits again under its locks: the artist holds a credit elsewhere now, so it
     * keeps the artist. An apply that left the artists it may merge out of its sorted key pass, or
     * locked their rows first, would wait on the row the import's credit holds instead of on the key.
     */
    public function testAnArtistAnImportCreditedWhileTheApplyWaitedIsKeptWithItsCredit(): void
    {
        $seed = MatchApplyRace::seed($this->observer, $this->tag);
        $selection = MatchApplyRace::compare(ArtistImportIdentityRace::entityManager($this->writer), MatchApplyRace::lookup($this->tag), $seed['albumPublicId'])
            |> MatchSelections::defaults(...);
        self::assertCount(1, $selection->artistActions, 'The admin saw the combined artist merge into Post Malone.');

        $this->writer->beginTransaction();
        $identity = ArtistImportIdentityRace::identities([[MatchApplyRace::duet($this->tag), []]])[0];
        $importedArtist = (ArtistImportIdentityRace::entityManager($this->writer) |> ArtistImportIdentityRace::resolver(...))->resolve([$identity])[$identity->key()]->toString();
        $this->writer->executeStatement(
            "INSERT INTO artist_song (id, artist_id, song_id, role, credited_name) VALUES (gen_random_uuid(), ?, ?, 'primary', ?)",
            [$importedArtist, $seed['otherSong'], MatchApplyRace::duet($this->tag)],
        );
        $this->child = MatchApplyRace::start($this->schema, 'apply', [
            'tag' => $this->tag,
            'albumPublicId' => $seed['albumPublicId'],
            'selection' => MatchSelections::toArray($selection),
        ]);
        self::assertSame('advisory', RetagLockRace::awaitLockWait($this->observer, $this->child)['waitsOn'], 'The apply takes the import keys before any row.');
        $this->writer->commit();
        $apply = ArtistImportIdentityRace::finish($this->child, $this->observer);
        $this->child = null;

        self::assertSame($seed['duetArtist'], $importedArtist);
        self::assertSame(['outcome' => 'applied', 'merged' => [], 'removed' => []], $apply);
        self::assertSame(
            [[MatchApplyRace::duet($this->tag), 'primary', MatchApplyRace::duet($this->tag)]],
            MatchApplyRace::songCredits($this->observer, $seed['otherSong']),
        );
        self::assertSame(
            [[MatchApplyRace::postMalone($this->tag), 'primary', MatchApplyRace::duet($this->tag)], [MatchApplyRace::lukeCombs($this->tag), 'featured', MatchApplyRace::lukeCombs($this->tag)]],
            MatchApplyRace::songCredits($this->observer, $seed['duetSong']),
        );
    }
}
