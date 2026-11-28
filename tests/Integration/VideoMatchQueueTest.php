<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\Model\Movie;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoProviderPauseRepository;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Fixtures\Catalog\VideoMatchQueue;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class VideoMatchQueueTest extends TestCase
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

    public function testRequestsKeepOneStatePerTitleAndTheEarlierAdminRequest(): void
    {
        $states = $this->queue->states;
        self::assertTrue($states->requestMovie($this->queue->movieId, 'ingest', $this->now));
        self::assertFalse($states->requestMovie($this->queue->movieId, 'ingest', $this->now->modify('+5 minutes')));
        self::assertTrue($states->requestMovie($this->queue->movieId, 'admin', $this->now->modify('-1 minute')));
        self::assertFalse($states->requestMovie($this->queue->movieId, 'ingest', $this->now));
        self::assertTrue($states->requestSeries($this->queue->seriesId, 'ingest', $this->now));

        $stored = $states->findByMovie($this->queue->movieId);
        self::assertNotNull($stored);
        self::assertSame('admin', $stored->source);
        self::assertEquals($this->now->modify('-1 minute'), $stored->dueAt);
        self::assertSame(2, (int) $this->queue->writer->fetchOne('SELECT count(*) FROM video_match_states'));
    }

    public function testARequestBesideALiveClaimSurvivesCompletion(): void
    {
        $states = $this->queue->states;
        $states->requestMovie($this->queue->movieId, 'ingest', $this->now);
        $token = Uuid::v7();
        $claim = $states->claimOldestDueRequest($this->now, $token, 900);
        self::assertNotNull($claim);
        self::assertFalse($states->requestMovie($this->queue->movieId, 'admin', $this->now->modify('+1 minute')));
        self::assertNull($states->claimOldestDueRequest($this->now->modify('+1 hour'), Uuid::v7(), 900));

        $pending = $states->completeClaim($claim->id, $token, $this->now);

        self::assertNotNull($pending);
        self::assertSame('requested', $pending->status);
        self::assertSame('admin', $pending->source);
        self::assertNull($pending->claimId);
        self::assertEquals($this->now->modify('+1 minute'), $pending->dueAt);
    }

    public function testReviewAwaitingProviderAndRecoveryTransitions(): void
    {
        $states = $this->queue->states;
        $states->markAwaitingSeries($this->queue->seriesId, 'ingest', $this->now);
        $awaiting = $states->awaitingProviders(1);
        self::assertCount(1, $awaiting);
        self::assertSame($this->queue->seriesId->toString(), $awaiting[0]->seriesId?->toString());
        self::assertSame([], $states->awaitingProviders(1, $awaiting[0]->id));
        self::assertNull($states->nextRequestDueAt());
        $states->requestSeries($this->queue->seriesId, 'ingest', $this->now);
        $token = Uuid::v7();
        $claim = $states->claimOldestDueRequest($this->now, $token, 900);
        self::assertNotNull($claim);

        $review = $states->markClaimedForReview($claim->id, $token, 'close_runner_up', 92, $this->now);

        self::assertNotNull($review);
        self::assertSame('needs_review', $review->status);
        self::assertSame('close_runner_up', $review->reason);
        self::assertSame(92, $review->bestScore);
        self::assertNull($states->nextRequestDueAt());
    }

    public function testPendingRequestClearsTheClaimedTitlesLoginFailureReason(): void
    {
        $states = $this->queue->states;
        $states->requestMovie($this->queue->movieId, 'ingest', $this->now);
        $token = Uuid::v7();
        $claim = $states->claimOldestDueRequest($this->now, $token, 900);
        self::assertNotNull($claim);
        $states->requestMovie($this->queue->movieId, 'admin', $this->now);

        $pending = $states->postponeClaimedRequest($claim->id, $token, $this->now->modify('+5 minutes'), 'login_failed');

        self::assertNotNull($pending);
        self::assertSame('requested', $pending->status);
        self::assertNull($pending->reason);
        self::assertEquals($this->now, $pending->dueAt);
        self::assertNull($states->postponeClaimedRequest($claim->id, $token, $this->now->modify('+1 hour'), 'login_failed'));
        self::assertNull($states->findByMovie($this->queue->movieId)?->reason);
    }

    public function testLeaseTakeoverFencesEveryLateOutcome(): void
    {
        $states = $this->queue->states;
        $states->requestMovie($this->queue->movieId, 'ingest', $this->now);
        $oldToken = Uuid::v7();
        $claim = $states->claimOldestDueRequest($this->now, $oldToken, 900);
        self::assertNotNull($claim);
        $this->queue->writer->executeStatement("UPDATE video_match_states SET claim_expires_at = clock_timestamp() - interval '1 second'");
        $newToken = Uuid::v7();
        self::assertNotNull($states->claimOldestDueRequest($this->now, $newToken, 900));

        self::assertNull($states->completeClaim($claim->id, $oldToken, $this->now));
        self::assertNull($states->markClaimedForReview($claim->id, $oldToken, 'no_candidates', null, $this->now));
        self::assertNull($states->markClaimedAwaitingProvider($claim->id, $oldToken, $this->now));
        self::assertNull($states->postponeClaimedRequest($claim->id, $oldToken, $this->now->modify('+1 hour')));
        self::assertNull($states->releaseClaim($claim->id, $oldToken));
        self::assertSame($newToken->toString(), $states->findByMovie($this->queue->movieId)?->claimId?->toString());
    }

    public function testDeferredReleasedAndAwaitingClaimsKeepTheirDueWork(): void
    {
        $states = $this->queue->states;
        $states->requestSeries($this->queue->seriesId, 'ingest', $this->now);
        $token = Uuid::v7();
        $claim = $states->claimOldestDueRequest($this->now, $token, 900);
        self::assertNotNull($claim);
        $later = $this->now->modify('+1 hour');
        $deferred = $states->postponeClaimedRequest($claim->id, $token, $later, 'login_failed');
        self::assertNotNull($deferred);
        self::assertEquals($later, $deferred->dueAt);
        self::assertSame('login_failed', $deferred->reason);
        self::assertEquals($later, $states->nextRequestDueAt());
        self::assertNull($states->claimOldestDueRequest($this->now, Uuid::v7(), 900));
        $next = Uuid::v7();
        self::assertNotNull($states->claimOldestDueRequest($later, $next, 900));
        self::assertSame('awaiting_provider', $states->markClaimedAwaitingProvider($claim->id, $next, $this->now)?->status);
        self::assertNull($states->nextRequestDueAt());
        $states->requestSeries($this->queue->seriesId, 'ingest', $this->now);
        $last = Uuid::v7();
        self::assertNotNull($states->claimOldestDueRequest($this->now, $last, 900));
        self::assertSame('requested', $states->releaseClaim($claim->id, $last)?->status);
        self::assertEquals($this->now, $states->nextRequestDueAt());
    }

    public function testDeletingATitleDeletesOnlyItsOwnMatchState(): void
    {
        $states = $this->queue->states;
        $states->requestMovie($this->queue->movieId, 'ingest', $this->now);
        $states->requestSeries($this->queue->seriesId, 'ingest', $this->now);

        $this->queue->writer->executeStatement('DELETE FROM movies WHERE id = ?', [$this->queue->movieId->toString()]);

        self::assertNull($states->findByMovie($this->queue->movieId));
        self::assertNotNull($states->findBySeries($this->queue->seriesId));
    }

    public function testApplyGuardRejectsExpiredClaimsAndRequiresATransaction(): void
    {
        $states = $this->queue->states;
        $states->requestMovie($this->queue->movieId, 'ingest', $this->now);
        $token = Uuid::v7();
        $claim = $states->claimOldestDueRequest($this->now, $token, 900);
        self::assertNotNull($claim);
        $this->queue->writer->beginTransaction();
        self::assertNotNull($states->lockClaimForApply($claim->id, $token));
        $this->queue->writer->executeStatement("UPDATE video_match_states SET claim_expires_at = clock_timestamp() - interval '1 second'");
        self::assertNull($states->lockClaimForApply($claim->id, $token));
        self::assertNull($states->completeClaim($claim->id, $token, $this->now));
        self::assertNull($states->markClaimedForReview($claim->id, $token, 'no_candidates', null, $this->now));
        self::assertNull($states->markClaimedAwaitingProvider($claim->id, $token, $this->now));
        self::assertNull($states->postponeClaimedRequest($claim->id, $token, $this->now->modify('+1 hour'), 'login_failed'));
        self::assertNull($states->releaseClaim($claim->id, $token));
        $this->queue->writer->rollBack();

        $this->expectException(LogicException::class);
        $states->lockClaimForApply($claim->id, $token);
    }

    public function testProviderPausesEscalateIndependentlyAndResetOnSuccess(): void
    {
        $pauses = new VideoProviderPauseRepository($this->queue->writer);
        foreach ([300, 900, 3600, 3600, 3600] as $index => $expected) {
            $pause = $pauses->pause('tvdb', 'unavailable');
            $remaining = (float) $this->queue->writer->fetchOne(
                "SELECT extract(epoch FROM retry_at - clock_timestamp()) FROM video_provider_pauses WHERE provider = 'tvdb'",
            );
            self::assertGreaterThan($expected - 2, $remaining);
            self::assertLessThanOrEqual($expected, $remaining);
            self::assertSame(min($index + 1, 4), $pause->failureCount);
        }
        self::assertNull($pauses->find('tmdb'));
        self::assertCount(1, $pauses->activePauses());
        $pauses->clear('tvdb');
        self::assertSame([], $pauses->activePauses());
        self::assertSame(1, $pauses->pause('tvdb', 'login_failed')->failureCount);
    }

    public function testMovieMatchIdentityRoundTripsWithoutChangingLockedMetadata(): void
    {
        $movies = new MovieRepository($this->queue->manager);
        $movie = $movies->findByUuid($this->queue->movieId);
        self::assertInstanceOf(Movie::class, $movie);
        $movie->edit(title: 'My Heat');
        $movies->save($movie);
        $video = new VideoEntity(new PublicId(), '/media/movies/Heat.mp4', hash('sha256', $this->queue->schema));
        $this->queue->manager->persist($video);
        $this->queue->manager->persist(new MovieVideoEntity(
            $this->queue->manager->getReference(MovieEntity::class, $movie->getId()),
            $video,
        ));
        $this->queue->manager->flush();
        $movie->match('tmdb', '949', ['tmdb' => '949', 'imdb' => 'tt0113277'], handMatched: true);
        $movies->saveMatchIdentity($movie);
        $this->queue->manager->clear();
        $stored = $movies->findByUuid($this->queue->movieId);

        self::assertNotNull($stored);
        self::assertSame('tmdb', $stored->getMatchedProvider());
        self::assertSame('949', $stored->getProviderId());
        self::assertEquals(['tmdb' => '949', 'imdb' => 'tt0113277'], $stored->getExternalIds());
        self::assertTrue($stored->isHandMatched());
        self::assertSame('My Heat', $stored->getTitle());
        self::assertSame(['title'], $stored->getLockedFields());
        self::assertSame([$video->getId()->toString()], $stored->getVideoIds());
    }
}
