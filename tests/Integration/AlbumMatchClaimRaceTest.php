<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Infrastructure\Doctrine\Repository\AlbumMatchStateRepository;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\AlbumMatchClaimRace;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * A request for an album and the outcome of the run that matched it meet on the album's row.
 * Whichever comes second waits for the first to commit and then acts on the row as the first left
 * it, so the request is never lost: an outcome that waited leaves the album waiting for the
 * request, and a request that waited asks for the match anew. Each test holds one side's
 * transaction open in the test session while the other side runs in a child process, waits until
 * the child waits on the row, then commits.
 */
final class AlbumMatchClaimRaceTest extends TestCase
{
    private const string NOW = '2026-11-14 12:00:00.000000 UTC';
    private const int LEASE = 900;

    private Connection $writer;
    private Connection $observer;
    private AlbumMatchStateRepository $states;
    private string $schema;
    /** @var array{process: resource, output: resource, application: string}|null */
    private ?array $child = null;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        $this->schema = 'album_match_claim_race_' . (random_bytes(8) |> bin2hex(...));
        $this->observer = ArtistImportIdentityRace::connect('public');
        self::assertStringStartsWith('18.', (string) $this->observer->fetchOne('SHOW server_version'));
        // A statement that waited acts on the row the other session committed only under READ COMMITTED.
        self::assertSame('read committed', $this->observer->fetchOne('SHOW default_transaction_isolation'));
        $this->observer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        ArtistImportIdentityRace::copyTableWithoutPgroonga($this->observer, 'album_match_states');

        $this->writer = ArtistImportIdentityRace::connect($this->schema);
        $this->states = AlbumMatchClaimRace::states($this->writer);
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
     * The run reads the album's row before the request commits and finds no request beside its
     * claim. Deciding on that reading would remove the row with the request, or pass the row over
     * and leave the claim on it until it lapses.
     */
    public function testAMatchedOutcomeThatWaitedForARequestLeavesTheAlbumWaitingForIt(): void
    {
        [$album, $claim] = $this->claimedAlbum();

        $this->writer->beginTransaction();
        self::assertFalse($this->states->request($album, AlbumMatchSource::Admin, self::at('+3 minutes')), 'The run that holds the album brings the next run.');
        $this->startChild('matched', ['album' => $album->toString(), 'claim' => $claim->toString()]);
        $this->writer->commit();

        self::assertSame(
            ['left' => ['status' => 'requested', 'source' => 'admin', 'dueAt' => self::at('+3 minutes')->format('Y-m-d H:i:s.uP')]],
            $this->finishChild(),
        );
        self::assertSame(
            ['state' => 'requested', 'source' => 'admin', 'due_at' => '2026-11-14 12:03:00+00', 'claim_id' => null, 'claim_expires_at' => null, 'next_due_at' => null],
            $this->row($album),
        );
    }

    public function testARequestThatWaitedForAMatchedOutcomeAsksForTheMatchAnew(): void
    {
        [$album, $claim] = $this->claimedAlbum();

        $this->writer->beginTransaction();
        self::assertNull($this->states->removeClaimedRequest($album, $claim), 'No request waits beside the claim, so the request is removed.');
        $this->startChild('request', ['album' => $album->toString(), 'source' => AlbumMatchSource::Admin->value, 'dueAt' => self::at('+3 minutes')->format(DATE_RFC3339_EXTENDED)]);
        $this->writer->commit();

        self::assertSame(['dueSooner' => true], $this->finishChild(), 'The album was not waiting any more, so the request brings its own run.');
        self::assertSame(
            ['state' => 'requested', 'source' => 'admin', 'due_at' => '2026-11-14 12:03:00+00', 'claim_id' => null, 'claim_expires_at' => null, 'next_due_at' => null],
            $this->row($album),
        );
    }

    private static function at(string $offset): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW . ' ' . $offset);
    }

    /**
     * An import's request for an album, claimed by a queue run.
     *
     * @return array{Uuid, Uuid} the album and the claim
     */
    private function claimedAlbum(): array
    {
        [$album, $claim] = [Uuid::v7(), Uuid::v7()];
        $this->states->request($album, AlbumMatchSource::Ingest, self::at('-1 minute'));
        self::assertSame($album->toString(), $this->states->claimOldestDueRequest(self::at('+0 minutes'), $claim, self::LEASE)?->albumId->toString());

        return [$album, $claim];
    }

    /**
     * Starts the other side in a second session and returns once it waits on the transaction that
     * changed the album's row.
     *
     * @param 'matched'|'request'   $step
     * @param array<string, string> $payload
     */
    private function startChild(string $step, array $payload): void
    {
        $this->child = AlbumMatchClaimRace::start($this->schema, $step, $payload);
        AlbumMatchClaimRace::awaitRowWait($this->observer, $this->child);
    }

    /** @return array<string, mixed> what the child printed */
    private function finishChild(): array
    {
        self::assertNotNull($this->child);
        $result = ArtistImportIdentityRace::finish($this->child, $this->observer);
        $this->child = null;

        return $result;
    }

    /** @return array<string, mixed> */
    private function row(Uuid $album): array
    {
        $row = $this->observer->fetchAssociative(
            'SELECT state, source, due_at, claim_id, claim_expires_at, next_due_at FROM album_match_states WHERE album_id = ?',
            [$album->toString()],
        );
        self::assertIsArray($row, 'The album has a match state.');

        return $row;
    }
}
