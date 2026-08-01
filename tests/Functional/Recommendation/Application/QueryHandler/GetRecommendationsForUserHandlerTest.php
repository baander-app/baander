<?php

declare(strict_types=1);

namespace App\Tests\Functional\Recommendation\Application\QueryHandler;

use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\AlbumState;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\ArtistState;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\Repository\ArtistRepositoryInterface;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Domain\Model\Image;
use App\Media\Domain\Model\ImageState;
use App\Recommendation\Application\Query\GetRecommendationsForUserQuery;
use App\Recommendation\Application\QueryHandler\GetRecommendationsForUserHandler;
use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\ValueObject\RecommendationType;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Tests\Functional\TestCase;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class GetRecommendationsForUserHandlerTest extends TestCase
{
    private RecommendationRepositoryInterface $recommendationRepository;
    private AlbumRepositoryInterface $albumRepository;
    private ArtistRepositoryInterface $artistRepository;
    private SongRepositoryInterface $songRepository;
    private ImagePortInterface&MockObject $imagePort;
    private RequestStack $requestStack;
    private GetRecommendationsForUserHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->recommendationRepository = $container->get(RecommendationRepositoryInterface::class);
        $this->albumRepository = $container->get(AlbumRepositoryInterface::class);
        $this->artistRepository = $container->get(ArtistRepositoryInterface::class);
        $this->songRepository = $container->get(SongRepositoryInterface::class);

        $this->imagePort = $this->createMock(ImagePortInterface::class);
        $this->requestStack = new RequestStack();
        $request = Request::create('https://example.com/api/recommendations');
        $this->requestStack->push($request);

        $this->handler = new GetRecommendationsForUserHandler(
            $this->recommendationRepository,
            $this->albumRepository,
            $this->artistRepository,
            $this->songRepository,
            $this->imagePort,
            $this->requestStack,
        );
    }

    public function testEnrichesAlbumRecommendationsWithTitleArtistAndCoverUrl(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId();
        $albumId = Uuid::v7();
        $artistName = 'Test Artist';
        $artistId = Uuid::v7();
        $coverImageId = Uuid::v7();
        $coverPublicId = new PublicId();

        // Create artist first (required for linkArtistToAlbum to work)
        $artist = Artist::reconstitute(new ArtistState(
            id: $artistId,
            publicId: new PublicId(),
            name: $artistName,
            country: 'US',
            gender: null,
            type: null,
            lifeSpanBegin: null,
            lifeSpanEnd: null,
            disambiguation: null,
            sortName: $artistName,
            biography: null,
            mbid: null,
            discogsId: null,
            spotifyId: null,
            coverImageId: null,
            lockedFields: [],
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));
        $this->artistRepository->save($artist);

        // Create image in database so album can reference it
        $this->createImageFixture($coverImageId, $coverPublicId, '/covers/cover.jpg', 'image/jpeg');

        // Create album
        $album = Album::reconstitute(new AlbumState(
            id: $albumId,
            publicId: new PublicId(),
            libraryId: $this->createLibraryFixture(),
            title: 'Test Album',
            type: 'studio',
            mbid: null,
            discogsId: null,
            spotifyId: null,
            year: 2024,
            label: null,
            catalogNumber: null,
            barcode: null,
            country: null,
            language: null,
            disambiguation: null,
            annotation: null,
            coverImageId: $coverImageId,
            lockedFields: [],
            mergedFrom: [],
        ));
        $this->albumRepository->save($album);

        // Link artist to album
        $this->albumRepository->linkArtistToAlbum($albumId, $artistName, 'main');

        // Create recommendation
        $recommendation = Recommendation::reconstitute(
            id: Uuid::v7(),
            name: 'default',
            sourceType: 'album',
            sourceId: Uuid::v7()->toString(),
            targetType: 'album',
            targetId: $albumId->toString(),
            score: 0.8,
            position: 1,
            userId: $userId,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );
        $this->recommendationRepository->save($recommendation);

        // Mock image port
        $image = Image::reconstitute(new ImageState(
            id: $coverImageId,
            publicId: $coverPublicId,
            path: '/covers/cover.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            blurhash: null,
            size: 12345,
            width: 1000,
            height: 1000,
            imageableType: 'album',
            albumId: $albumId,
            artistId: null,
            playlistId: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));

        $this->imagePort
            ->method('findByUuids')
            ->willReturnCallback(function (array $uuids) use ($image) {
                return [$image->getId()->toString() => $image];
            });

        $query = new GetRecommendationsForUserQuery($userId, 10);
        $result = ($this->handler)($query);

        $this->assertCount(1, $result);
        $this->assertSame('Test Album', $result[0]['targetTitle']);
        $this->assertSame('Test Artist', $result[0]['targetArtistName']);
        $this->assertSame('https://example.com/api/images/' . $coverPublicId->toString() . '/file', $result[0]['coverImageUrl']);
    }

    public function testEnrichesArtistRecommendationsWithNameAndCoverUrl(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId();
        $artistId = Uuid::v7();
        $coverImageId = Uuid::v7();
        $coverPublicId = new PublicId();

        // Create image in database so artist can reference it
        $this->createImageFixture($coverImageId, $coverPublicId, '/artists/artist.jpg', 'image/jpeg');

        // Create artist
        $artist = Artist::reconstitute(new ArtistState(
            id: $artistId,
            publicId: new PublicId(),
            name: 'Test Artist',
            country: 'US',
            gender: null,
            type: null,
            lifeSpanBegin: null,
            lifeSpanEnd: null,
            disambiguation: null,
            sortName: 'Artist, Test',
            biography: null,
            mbid: null,
            discogsId: null,
            spotifyId: null,
            coverImageId: $coverImageId,
            lockedFields: [],
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));
        $this->artistRepository->save($artist);

        // Create recommendation
        $recommendation = Recommendation::reconstitute(
            id: Uuid::v7(),
            name: 'default',
            sourceType: 'artist',
            sourceId: Uuid::v7()->toString(),
            targetType: 'artist',
            targetId: $artistId->toString(),
            score: 0.9,
            position: 1,
            userId: $userId,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );
        $this->recommendationRepository->save($recommendation);

        // Mock image port
        $image = Image::reconstitute(new ImageState(
            id: $coverImageId,
            publicId: $coverPublicId,
            path: '/artists/artist.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            blurhash: null,
            size: 10000,
            width: 500,
            height: 500,
            imageableType: 'artist',
            albumId: null,
            artistId: $artistId,
            playlistId: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));

        $this->imagePort
            ->method('findByUuids')
            ->willReturnCallback(function (array $uuids) use ($image) {
                return [$image->getId()->toString() => $image];
            });

        $query = new GetRecommendationsForUserQuery($userId, 10);
        $result = ($this->handler)($query);

        $this->assertCount(1, $result);
        $this->assertSame('Test Artist', $result[0]['targetTitle']);
        $this->assertNull($result[0]['targetArtistName']);
        $this->assertSame('https://example.com/api/images/' . $coverPublicId->toString() . '/file', $result[0]['coverImageUrl']);
    }

    public function testHandlesMissingAlbumWithNullFields(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId();
        $nonExistentAlbumId = Uuid::v7();

        // Create recommendation pointing to non-existent album
        $recommendation = Recommendation::reconstitute(
            id: Uuid::v7(),
            name: 'default',
            sourceType: 'album',
            sourceId: Uuid::v7()->toString(),
            targetType: 'album',
            targetId: $nonExistentAlbumId->toString(),
            score: 0.5,
            position: 1,
            userId: $userId,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );
        $this->recommendationRepository->save($recommendation);

        $this->imagePort
            ->method('findByUuids')
            ->willReturn([]);

        $query = new GetRecommendationsForUserQuery($userId, 10);
        $result = ($this->handler)($query);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['targetTitle']);
        $this->assertNull($result[0]['targetArtistName']);
        $this->assertNull($result[0]['coverImageUrl']);
    }

    public function testHandlesAlbumWithoutCoverArt(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId();
        $albumId = Uuid::v7();
        $artistName = 'Test Artist';
        $artistId = Uuid::v7();

        // Create artist first
        $artist = Artist::reconstitute(new ArtistState(
            id: $artistId,
            publicId: new PublicId(),
            name: $artistName,
            country: 'US',
            gender: null,
            type: null,
            lifeSpanBegin: null,
            lifeSpanEnd: null,
            disambiguation: null,
            sortName: $artistName,
            biography: null,
            mbid: null,
            discogsId: null,
            spotifyId: null,
            coverImageId: null,
            lockedFields: [],
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));
        $this->artistRepository->save($artist);

        // Create album without cover
        $album = Album::reconstitute(new AlbumState(
            id: $albumId,
            publicId: new PublicId(),
            libraryId: $this->createLibraryFixture(),
            title: 'No Cover Album',
            type: 'studio',
            mbid: null,
            discogsId: null,
            spotifyId: null,
            year: 2024,
            label: null,
            catalogNumber: null,
            barcode: null,
            country: null,
            language: null,
            disambiguation: null,
            annotation: null,
            coverImageId: null,
            lockedFields: [],
            mergedFrom: [],
        ));
        $this->albumRepository->save($album);

        // Link artist to album
        $this->albumRepository->linkArtistToAlbum($albumId, $artistName, 'main');

        $recommendation = Recommendation::reconstitute(
            id: Uuid::v7(),
            name: 'default',
            sourceType: 'album',
            sourceId: Uuid::v7()->toString(),
            targetType: 'album',
            targetId: $albumId->toString(),
            score: 0.7,
            position: 1,
            userId: $userId,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );
        $this->recommendationRepository->save($recommendation);

        $this->imagePort
            ->method('findByUuids')
            ->willReturn([]);

        $query = new GetRecommendationsForUserQuery($userId, 10);
        $result = ($this->handler)($query);

        $this->assertCount(1, $result);
        $this->assertSame('No Cover Album', $result[0]['targetTitle']);
        $this->assertSame('Test Artist', $result[0]['targetArtistName']);
        $this->assertNull($result[0]['coverImageUrl']);
    }

    public function testHandlesAlbumWithoutAssociatedArtists(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId();
        $albumId = Uuid::v7();

        // Create album without linked artists
        $album = Album::reconstitute(new AlbumState(
            id: $albumId,
            publicId: new PublicId(),
            libraryId: $this->createLibraryFixture(),
            title: 'No Artist Album',
            type: 'studio',
            mbid: null,
            discogsId: null,
            spotifyId: null,
            year: 2024,
            label: null,
            catalogNumber: null,
            barcode: null,
            country: null,
            language: null,
            disambiguation: null,
            annotation: null,
            coverImageId: null,
            lockedFields: [],
            mergedFrom: [],
        ));
        $this->albumRepository->save($album);

        $recommendation = Recommendation::reconstitute(
            id: Uuid::v7(),
            name: 'default',
            sourceType: 'album',
            sourceId: Uuid::v7()->toString(),
            targetType: 'album',
            targetId: $albumId->toString(),
            score: 0.6,
            position: 1,
            userId: $userId,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );
        $this->recommendationRepository->save($recommendation);

        $this->imagePort
            ->method('findByUuids')
            ->willReturn([]);

        $query = new GetRecommendationsForUserQuery($userId, 10);
        $result = ($this->handler)($query);

        $this->assertCount(1, $result);
        $this->assertSame('No Artist Album', $result[0]['targetTitle']);
        $this->assertNull($result[0]['targetArtistName']);
        $this->assertNull($result[0]['coverImageUrl']);
    }

    public function testReturnsEmptyArrayForNoRecommendations(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId();

        $this->imagePort
            ->method('findByUuids')
            ->willReturn([]);

        $query = new GetRecommendationsForUserQuery($userId, 10);
        $result = ($this->handler)($query);

        $this->assertSame([], $result);
    }

    public function testHandlesMultipleRecommendationsOfDifferentTypes(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId();
        $albumId = Uuid::v7();
        $artistId = Uuid::v7();
        $artistName = 'Mixed Artist';
        $coverImageId = Uuid::v7();
        $coverPublicId = new PublicId();

        // Create image in database first
        $this->createImageFixture($coverImageId, $coverPublicId, '/covers/cover.jpg', 'image/jpeg');

        // Create artist first (required for linkArtistToAlbum)
        $artist = Artist::reconstitute(new ArtistState(
            id: $artistId,
            publicId: new PublicId(),
            name: $artistName,
            country: 'UK',
            gender: null,
            type: null,
            lifeSpanBegin: null,
            lifeSpanEnd: null,
            disambiguation: null,
            sortName: 'Artist, Mixed',
            biography: null,
            mbid: null,
            discogsId: null,
            spotifyId: null,
            coverImageId: $coverImageId,
            lockedFields: [],
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));
        $this->artistRepository->save($artist);

        // Create album
        $album = Album::reconstitute(new AlbumState(
            id: $albumId,
            publicId: new PublicId(),
            libraryId: $this->createLibraryFixture(),
            title: 'Mixed Album',
            type: 'studio',
            mbid: null,
            discogsId: null,
            spotifyId: null,
            year: 2024,
            label: null,
            catalogNumber: null,
            barcode: null,
            country: null,
            language: null,
            disambiguation: null,
            annotation: null,
            coverImageId: $coverImageId,
            lockedFields: [],
            mergedFrom: [],
        ));
        $this->albumRepository->save($album);
        $this->albumRepository->linkArtistToAlbum($albumId, $artistName, 'main');

        // Create album recommendation
        $albumRec = Recommendation::reconstitute(
            id: Uuid::v7(),
            name: 'default',
            sourceType: 'album',
            sourceId: Uuid::v7()->toString(),
            targetType: 'album',
            targetId: $albumId->toString(),
            score: 0.8,
            position: 1,
            userId: $userId,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );
        $this->recommendationRepository->save($albumRec);

        // Create artist recommendation
        $artistRec = Recommendation::reconstitute(
            id: Uuid::v7(),
            name: 'default',
            sourceType: 'album',
            sourceId: Uuid::v7()->toString(),
            targetType: 'artist',
            targetId: $artistId->toString(),
            score: 0.9,
            position: 2,
            userId: $userId,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );
        $this->recommendationRepository->save($artistRec);

        // Mock image port
        $image = Image::reconstitute(new ImageState(
            id: $coverImageId,
            publicId: $coverPublicId,
            path: '/covers/cover.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            blurhash: null,
            size: 12345,
            width: 1000,
            height: 1000,
            imageableType: 'album',
            albumId: $albumId,
            artistId: null,
            playlistId: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));

        $this->imagePort
            ->method('findByUuids')
            ->willReturnCallback(function (array $uuids) use ($image) {
                return [$image->getId()->toString() => $image];
            });

        $query = new GetRecommendationsForUserQuery($userId, 10);
        $result = ($this->handler)($query);

        $this->assertCount(2, $result);

        // Find album and artist results (order may vary)
        $albumResult = null;
        $artistResult = null;
        foreach ($result as $r) {
            if ($r['target_id'] === $albumId->toString()) {
                $albumResult = $r;
            } elseif ($r['target_id'] === $artistId->toString()) {
                $artistResult = $r;
            }
        }

        $this->assertNotNull($albumResult);
        $this->assertNotNull($artistResult);

        $this->assertSame('Mixed Album', $albumResult['targetTitle']);
        $this->assertSame('Mixed Artist', $albumResult['targetArtistName']);
        $this->assertSame('https://example.com/api/images/' . $coverPublicId->toString() . '/file', $albumResult['coverImageUrl']);

        $this->assertSame('Mixed Artist', $artistResult['targetTitle']);
        $this->assertNull($artistResult['targetArtistName']);
        $this->assertSame('https://example.com/api/images/' . $coverPublicId->toString() . '/file', $artistResult['coverImageUrl']);
    }

    public function testEnrichesSourceNameForAlbumAndArtistSources(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId();

        // Create source album
        $sourceAlbumId = Uuid::v7();
        $sourceAlbumName = 'Source Album';
        $sourceArtistId = Uuid::v7();
        $sourceArtistName = 'Source Artist';

        // Create source artist
        $sourceArtist = Artist::reconstitute(new ArtistState(
            id: $sourceArtistId,
            publicId: new PublicId(),
            name: $sourceArtistName,
            country: 'US',
            gender: null,
            type: null,
            lifeSpanBegin: null,
            lifeSpanEnd: null,
            disambiguation: null,
            sortName: $sourceArtistName,
            biography: null,
            mbid: null,
            discogsId: null,
            spotifyId: null,
            coverImageId: null,
            lockedFields: [],
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));
        $this->artistRepository->save($sourceArtist);

        // Create source album
        $sourceAlbum = Album::reconstitute(new AlbumState(
            id: $sourceAlbumId,
            publicId: new PublicId(),
            libraryId: $this->createLibraryFixture(),
            title: $sourceAlbumName,
            type: 'album',
            mbid: null,
            discogsId: null,
            spotifyId: null,
            year: 2024,
            label: null,
            catalogNumber: null,
            barcode: null,
            country: null,
            language: null,
            disambiguation: null,
            annotation: null,
            coverImageId: null,
            lockedFields: [],
            mergedFrom: [],
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));
        $this->albumRepository->save($sourceAlbum);

        // Link artist to source album
        $this->albumRepository->linkArtistToAlbum($sourceAlbum->getId(), $sourceArtistName, 'main');

        // Create target album for recommendations
        $targetAlbumId = Uuid::v7();

        // Create recommendation from album source
        $rec1 = Recommendation::reconstitute(
            id: Uuid::v7(),
            name: 'content',
            sourceType: 'album',
            sourceId: $sourceAlbumId->toString(),
            targetType: 'album',
            targetId: $targetAlbumId->toString(),
            score: 0.9,
            position: 1,
            userId: $userId,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );
        $this->recommendationRepository->save($rec1);

        // Create recommendation from artist source
        $rec2 = Recommendation::reconstitute(
            id: Uuid::v7(),
            name: 'similar-artists',
            sourceType: 'artist',
            sourceId: $sourceArtistId->toString(),
            targetType: 'album',
            targetId: $targetAlbumId->toString(),
            score: 0.8,
            position: 2,
            userId: $userId,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );
        $this->recommendationRepository->save($rec2);

        $query = new GetRecommendationsForUserQuery($userId, 10);
        $result = ($this->handler)($query);

        $this->assertCount(2, $result);

        // Find results by source type
        $fromAlbumResult = null;
        $fromArtistResult = null;
        foreach ($result as $r) {
            if ($r['source_type'] === 'album') {
                $fromAlbumResult = $r;
            } elseif ($r['source_type'] === 'artist') {
                $fromArtistResult = $r;
            }
        }

        $this->assertNotNull($fromAlbumResult);
        $this->assertNotNull($fromArtistResult);

        $this->assertSame($sourceAlbumName, $fromAlbumResult['sourceName']);
        $this->assertSame($sourceArtistName, $fromArtistResult['sourceName']);
    }

    private function createLibraryFixture(): Uuid
    {
        $libraryId = Uuid::v7();
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO libraries (id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $libraryId->toString(),
                'test-lib-' . bin2hex(random_bytes(4)),
                'Test Library',
                '/tmp/test-' . bin2hex(random_bytes(4)),
                'music',
                0,
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        return $libraryId;
    }

    private function createImageFixture(Uuid $imageId, PublicId $publicId, string $path, string $mimeType): void
    {
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO images (id, public_id, path, extension, mime_type, blurhash, size, width, height, imageable_type, album_id, artist_id, playlist_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $imageId->toString(),
                $publicId->toString(),
                $path,
                pathinfo($path, PATHINFO_EXTENSION),
                $mimeType,
                null,
                12345,
                1000,
                1000,
                'album',
                null,
                null,
                null,
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );
    }
}
