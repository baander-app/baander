<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Auth\Domain\Model\User;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\AlbumMatchStateRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/admin/metadata/match/reviews lists the albums the matcher left for an admin, the most
 * recently checked first, a page at a time, and only to admins.
 */
final class MusicBrainzMatchReviewsTest extends TestCase
{
    private LibraryEntity $library;
    private AlbumMatchStateRepository $matchStates;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = random_bytes(4) |> bin2hex(...);
        $this->library = new LibraryEntity('Match Reviews', 'match-reviews-' . $suffix, '/media/match-reviews-' . $suffix, 'music', 'local');
        $this->entityManager->persist($this->library);
        $this->entityManager->flush();
        // Built directly, so the test does not depend on a consumer keeping the private service in the container.
        $this->matchStates = new AlbumMatchStateRepository($this->entityManager->getConnection());
    }

    public function testTwoReviewedAlbumsListNewestFirstWithTheirReasons(): void
    {
        $older = $this->album('Unknown Demos');
        $newer = $this->album('F-1 Trillion: Long Bed');
        $waiting = $this->album('Still Waiting');
        $this->review($older, AlbumMatchReviewReason::NoCandidates, '2026-11-14 09:00:00.000000 UTC', null);
        $this->review($newer, AlbumMatchReviewReason::TrackCountDiffers, '2026-11-14 12:36:07.500000 UTC', 84);
        $this->matchStates->save(new AlbumMatchState(
            albumId: $waiting->getId(),
            status: AlbumMatchStatus::Requested,
            source: AlbumMatchSource::Ingest,
            dueAt: new \DateTimeImmutable('2026-11-14 13:00:00 UTC'),
        ));

        $data = $this->reviews('', $this->createAdminUser());

        self::assertSame([
            'items' => [
                [
                    'albumPublicId' => $newer->getPublicId()->toString(),
                    'title' => 'F-1 Trillion: Long Bed',
                    'reason' => 'track_count_differs',
                    'checkedAt' => '2026-11-14T12:36:07+00:00',
                    'bestScore' => 84,
                ],
                [
                    'albumPublicId' => $older->getPublicId()->toString(),
                    'title' => 'Unknown Demos',
                    'reason' => 'no_candidates',
                    'checkedAt' => '2026-11-14T09:00:00+00:00',
                    'bestScore' => null,
                ],
            ],
            'next_cursor' => null,
            'has_next_page' => false,
            'per_page' => 50,
        ], $data);
    }

    /**
     * Two of the albums were checked in the same microsecond, so the album's public ID orders them;
     * the third was checked a microsecond later.
     */
    public function testPagingReturnsTheNextPageWithoutRepeats(): void
    {
        $admin = $this->createAdminUser();
        $this->review($this->album('Tied First', 'matchReviewTiedAlbum2'), AlbumMatchReviewReason::LowScore, '2026-11-14 10:00:00.000001 UTC', 41);
        $this->review($this->album('Tied Second', 'matchReviewTiedAlbum1'), AlbumMatchReviewReason::CloseRunnerUp, '2026-11-14 10:00:00.000001 UTC', 90);
        $this->review($this->album('Checked Last'), AlbumMatchReviewReason::TrackLengthsDiffer, '2026-11-14 10:00:00.000002 UTC', 77);

        $titles = [];
        $cursor = null;
        for ($page = 1; $page <= 4; ++$page) {
            $data = $this->reviews('limit=1' . ($cursor === null ? '' : '&cursor=' . $cursor), $admin);
            self::assertCount(1, $data['items'], sprintf('page %d', $page));
            $titles[] = $data['items'][0]['title'];
            $cursor = $data['next_cursor'];
            self::assertSame($cursor !== null, $data['has_next_page']);
            if ($cursor === null) {
                break;
            }
            self::assertIsString($cursor);
        }

        self::assertSame(['Checked Last', 'Tied First', 'Tied Second'], $titles);
    }

    public function testANonAdminIsRefused(): void
    {
        $this->review($this->album('Unknown Demos'), AlbumMatchReviewReason::NoCandidates, '2026-11-14 09:00:00 UTC', null);

        $response = $this->authenticatedRequest('GET', '/api/admin/metadata/match/reviews', $this->createTestUser());

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), (string) $response->getContent());
    }

    /** @return array<string, mixed> the response's data */
    private function reviews(string $query, User $admin): array
    {
        $body = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/admin/metadata/match/reviews' . ($query === '' ? '' : '?' . $query), $admin),
            Response::HTTP_OK,
            'data',
        );

        return $body['data'];
    }

    private function album(string $title, ?string $publicId = null): AlbumEntity
    {
        $album = new AlbumEntity($publicId === null ? new PublicId() : PublicId::fromString($publicId), $this->library, $title, 'album');
        $this->entityManager->persist($album);
        $this->entityManager->flush();

        return $album;
    }

    private function review(AlbumEntity $album, AlbumMatchReviewReason $reason, string $checkedAt, ?int $bestScore): void
    {
        $this->matchStates->save(new AlbumMatchState(
            albumId: $album->getId(),
            status: AlbumMatchStatus::NeedsReview,
            source: AlbumMatchSource::Ingest,
            dueAt: new \DateTimeImmutable('2026-11-14 08:00:00 UTC'),
            reason: $reason,
            checkedSongCount: 12,
            checkedAt: new \DateTimeImmutable($checkedAt),
            bestScore: $bestScore,
        ));
    }
}
