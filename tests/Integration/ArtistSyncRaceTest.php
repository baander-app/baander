<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\ArtistSyncRace;
use App\Tests\Fixtures\Catalog\MatchApplyRace;
use App\Tests\Fixtures\Catalog\RetagLockRace;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\FakeMusicBrainzLookup;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * An artist sync looks the artist up before it takes any lock, then locks the artist's row, reads
 * the artist again and fills only what is still empty and unlocked as another writer left it. The
 * first tests commit the other writer during the lookup, through a connection of its own; the last
 * holds an apply's transaction open in the test session while the sync runs in a child process,
 * waits until the child waits on the artist's row, then commits. The tables copy the migrated ones
 * without their PGroonga indexes or triggers; the foreign keys of credits are added back.
 */
final class ArtistSyncRaceTest extends TestCase
{
    private const string POST_MALONE_ID = 'b1e26560-60e5-4236-bbdb-9aa5a8d5ee19';
    private const string AUSTIN_POST_ID = '2b1d4a4e-7c0f-4d6e-9a1b-3c5d7e9f1a02';

    private Connection $writer;
    private Connection $observer;
    private Connection $other;
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
        $this->schema = 'artist_sync_race_' . (random_bytes(8) |> bin2hex(...));
        $this->observer = ArtistImportIdentityRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->observer->fetchOne('SHOW server_version'));
        // The read after the row lock sees a competing commit only under READ COMMITTED.
        self::assertSame('read committed', $this->observer->fetchOne('SHOW default_transaction_isolation'));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        MatchApplyRace::copyTables($this->observer);

        $this->writer = ArtistImportIdentityRace::connect($this->schema);
        $this->other = ArtistImportIdentityRace::connect($this->schema);
    }

    protected function tearDown(): void
    {
        foreach (['writer', 'other'] as $connection) {
            if (isset($this->{$connection})) {
                while ($this->{$connection}->isTransactionActive()) {
                    $this->{$connection}->rollBack();
                }
                $this->{$connection}->close();
            }
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
     * Another session merges the artist into another one while MusicBrainz is asked. The sync finds
     * the artist gone under its lock and writes nothing: the merged artist does not come back, and
     * the artist it was merged into keeps what it had.
     */
    public function testAnArtistMergedIntoAnotherDuringTheLookupIsNotWrittenAndDoesNotComeBack(): void
    {
        $artist = ArtistSyncRace::insertArtist($this->observer, $this->tagged('Post Malone'), self::POST_MALONE_ID);
        $holder = ArtistSyncRace::insertArtist($this->observer, $this->tagged('Austin Post'), self::AUSTIN_POST_ID);
        $lookup = new FakeMusicBrainzLookup();
        $lookup->artists[self::POST_MALONE_ID] = ArtistSyncRace::answer(self::POST_MALONE_ID, 'Post Malone', 'US');
        $lookup->duringArtistLookup = function () use ($artist, $holder): void {
            $merges = MatchApplyRace::services(ArtistImportIdentityRace::entityManager($this->other), null)['merges'];
            $this->other->transactional(static function () use ($merges, $artist, $holder): void {
                self::merge($merges, $artist, $holder);
            });
        };

        ArtistSyncRace::handler(ArtistImportIdentityRace::entityManager($this->writer), $lookup)(new SyncArtistMessage(new Uuid($artist)));

        self::assertFalse(ArtistSyncRace::row($this->observer, $artist));
        self::assertSame(
            ['name' => $this->tagged('Austin Post'), 'mbid' => self::AUSTIN_POST_ID, 'country' => null, 'type' => null, 'sort_name' => null],
            ArtistSyncRace::row($this->observer, $holder),
        );
    }

    /**
     * An admin matches the artist to another MusicBrainz artist while the sync asks about the ID
     * the artist held. The sync reads the artist again under its lock, finds the other ID and
     * writes nothing, so the match's values stay.
     */
    public function testAManualArtistMatchGivingAnotherIdDuringTheLookupKeepsTheMatchesValues(): void
    {
        $artist = ArtistSyncRace::insertArtist($this->observer, $this->tagged('Post Malone'), self::POST_MALONE_ID);
        $publicId = (string) $this->observer->fetchOne('SELECT public_id FROM artists WHERE id = ?', [$artist]);
        $lookup = new FakeMusicBrainzLookup();
        $lookup->artists[self::POST_MALONE_ID] = ArtistSyncRace::answer(self::POST_MALONE_ID, 'Post Malone', 'US', 'Person', 'Malone, Post');
        $lookup->duringArtistLookup = function () use ($publicId): void {
            $applied = ArtistSyncRace::applyArtistMatch(
                ArtistImportIdentityRace::entityManager($this->other),
                $publicId,
                ArtistSyncRace::answer(self::AUSTIN_POST_ID, $this->tagged('Post Malone'), 'GB', 'Group', 'Post, Austin'),
            );
            self::assertTrue($applied->isApplied());
        };

        ArtistSyncRace::handler(ArtistImportIdentityRace::entityManager($this->writer), $lookup)(new SyncArtistMessage(new Uuid($artist)));

        self::assertSame(
            ['name' => $this->tagged('Post Malone'), 'mbid' => self::AUSTIN_POST_ID, 'country' => 'GB', 'type' => 'Group', 'sort_name' => 'Post, Austin'],
            ArtistSyncRace::row($this->observer, $artist),
        );
    }

    /**
     * A sync that starts while a manual artist match holds the artist's row reads the artist before
     * the match commits, asks MusicBrainz, then waits on the row. Once the match commits, it reads
     * the artist as the match left it: it keeps the match's country and type and fills only the
     * sort name the match left empty.
     */
    public function testASyncWhileAnApplyHoldsTheArtistWaitsThenReadsTheArtistAsTheApplyLeftIt(): void
    {
        $artist = ArtistSyncRace::insertArtist($this->observer, $this->tagged('Post Malone'), self::POST_MALONE_ID);
        $publicId = (string) $this->observer->fetchOne('SELECT public_id FROM artists WHERE id = ?', [$artist]);
        $candidate = new MusicBrainzArtist(self::POST_MALONE_ID, $this->tagged('Post Malone'), 0, null, 'Group', 'GB', null, null, null, false);

        $applied = ArtistSyncRace::applyArtistMatch(ArtistImportIdentityRace::entityManager($this->writer), $publicId, $candidate, function () use ($artist): void {
            $this->child = ArtistSyncRace::start($this->schema, [
                'artistId' => $artist, 'mbid' => self::POST_MALONE_ID, 'name' => 'Post Malone',
                'country' => 'US', 'type' => 'Person', 'sortName' => 'Malone, Post',
            ]);
            self::assertContains(RetagLockRace::awaitLockWait($this->observer, $this->child)['waitsOn'], ['transactionid', 'tuple'], 'The sync waits on the artist\'s row.');
        });
        self::assertNotNull($this->child);
        $sync = ArtistImportIdentityRace::finish($this->child, $this->observer);
        $this->child = null;

        self::assertTrue($applied->isApplied());
        self::assertSame(['outcome' => 'synced'], $sync);
        self::assertSame(
            ['name' => $this->tagged('Post Malone'), 'mbid' => self::POST_MALONE_ID, 'country' => 'GB', 'type' => 'Group', 'sort_name' => 'Malone, Post'],
            ArtistSyncRace::row($this->observer, $artist),
        );
    }

    private static function merge(ArtistMergePortInterface $merges, string $source, string $target): void
    {
        $merges->lock([new Uuid($source), new Uuid($target)]);
        $removal = $merges->merge(new Uuid($source), new Uuid($target));
        self::assertSame([], $removal->imagesToDiscard);
    }

    /** The artist name in this test; advisory locks span the database. */
    private function tagged(string $name): string
    {
        return $name . ' ' . $this->tag;
    }
}
