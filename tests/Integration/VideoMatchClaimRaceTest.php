<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\VideoMatchQueue;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class VideoMatchClaimRaceTest extends TestCase
{
    private VideoMatchQueue $queue;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to migrated disposable PostgreSQL.');
        }
        $this->queue = new VideoMatchQueue();
        $this->now = new DateTimeImmutable();
    }

    protected function tearDown(): void
    {
        if (isset($this->queue)) {
            $this->queue->close();
        }
    }

    public function testAConcurrentClaimSkipsTheLockedTitleAndClaimsTheNextOne(): void
    {
        $states = $this->queue->states;
        $states->requestMovie($this->queue->movieId, 'ingest', $this->now->modify('-1 minute'));
        $states->requestSeries($this->queue->seriesId, 'ingest', $this->now->modify('-30 seconds'));
        $this->queue->writer->beginTransaction();
        $firstToken = Uuid::v7();
        $first = $states->claimOldestDueRequest($this->now, $firstToken, 900);
        self::assertNotNull($first);
        $secondToken = Uuid::v7();
        $child = $this->queue->start('claim', ['claim' => $secondToken->toString()]);

        $result = ArtistImportIdentityRace::finish($child, $this->queue->observer);

        self::assertTrue($this->queue->writer->isTransactionActive());
        self::assertSame($this->queue->seriesId->toString(), $result['result']['seriesId']);
        self::assertNotSame($first->id->toString(), $result['result']['id']);
        $this->queue->writer->commit();
        self::assertSame($firstToken->toString(), $states->findByMovie($this->queue->movieId)?->claimId?->toString());
        self::assertSame($secondToken->toString(), $states->findBySeries($this->queue->seriesId)?->claimId?->toString());
    }

    public function testCompletionWaitsForAPendingRequestAndPreservesIt(): void
    {
        $states = $this->queue->states;
        $states->requestMovie($this->queue->movieId, 'ingest', $this->now);
        $token = Uuid::v7();
        $claim = $states->claimOldestDueRequest($this->now, $token, 900);
        self::assertNotNull($claim);
        $this->queue->writer->beginTransaction();
        $states->requestMovie($this->queue->movieId, 'admin', $this->now);
        $child = $this->queue->start('complete', ['id' => $claim->id->toString(), 'claim' => $token->toString()]);
        try {
            $this->queue->awaitRowWait($child);
            $this->queue->writer->commit();
        } finally {
            while ($this->queue->writer->isTransactionActive()) {
                $this->queue->writer->rollBack();
            }
            $result = ArtistImportIdentityRace::finish($child, $this->queue->observer);
        }

        self::assertSame('requested', $result['result']['status']);
        self::assertSame('admin', $result['result']['source']);
        self::assertNull($result['result']['claimId']);
        self::assertEquals($this->now, $states->findByMovie($this->queue->movieId)?->dueAt);
    }

    public function testARequestWaitsForGuardedApplyAndRequeuesItsCompletedTitle(): void
    {
        $states = $this->queue->states;
        $states->requestMovie($this->queue->movieId, 'ingest', $this->now);
        $token = Uuid::v7();
        $claim = $states->claimOldestDueRequest($this->now, $token, 900);
        self::assertNotNull($claim);
        $this->queue->writer->beginTransaction();
        self::assertNotNull($states->lockClaimForApply($claim->id, $token));
        $child = $this->queue->start('request', ['movie' => $this->queue->movieId->toString()]);
        try {
            $this->queue->awaitRowWait($child);
            self::assertSame('matched', $states->completeClaim($claim->id, $token, $this->now)?->status);
            $this->queue->writer->commit();
        } finally {
            while ($this->queue->writer->isTransactionActive()) {
                $this->queue->writer->rollBack();
            }
            ArtistImportIdentityRace::finish($child, $this->queue->observer);
        }

        $stored = $states->findByMovie($this->queue->movieId);
        self::assertNotNull($stored);
        self::assertSame('requested', $stored->status);
        self::assertSame('admin', $stored->source);
    }
}
