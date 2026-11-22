<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Interface\Controller;

use App\Activity\Application\Port\ActivityIdentityProjectionPortInterface;
use App\Activity\Application\Port\ActivityPortInterface;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Infrastructure\ActivityEnrichmentService;
use App\Activity\Interface\Controller\ActivityController;
use App\Activity\Interface\Controller\UserRecentController;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongAvailabilityPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Domain\Model\Image;
use App\Shared\Application\Http\PublicOrigins;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ActivityCoverUrlOriginTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function endpoints(): iterable
    {
        yield 'history' => ['history'];
        yield 'recent' => ['recent'];
    }

    #[DataProvider('endpoints')]
    public function testCoverUrlsStartWithThePublicOriginUnderAForgedHost(string $endpoint): void
    {
        $cover = Image::create('/covers/cover.jpg', 'jpg', 'image/jpeg', 1, 1, 1, 'album');
        $album = Album::create(new Uuid(), 'Album', 'studio');
        $album->setCoverImage($cover->getId());
        $song = Song::create($album->getId(), 'Song', '/media/song.flac', 1, 'audio/flac');
        $userId = new Uuid();
        $activity = MediaActivity::create($userId, 'play', $song->getId(), $album->getId());
        $activities = $this->createStub(ActivityPortInterface::class);
        $activities->method('findByUser')->willReturn([$activity]);
        $activities->method('getRecentlyPlayed')->willReturn([$activity]);
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByUuids')->willReturn([$song->getId()->toString() => $song]);
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByUuids')->willReturn([$album->getId()->toString() => $album]);
        $images = $this->createStub(ImagePortInterface::class);
        $images->method('findByUuids')->willReturn([$cover->getId()->toString() => $cover]);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->principal($userId));
        $enrichment = $this->enrichment($songs, $albums, $images);
        $publicOrigins = new PublicOrigins('https://baander.app');
        $request = Request::create('https://other.baander.app/api/activity');

        $response = match ($endpoint) {
            'history' => $this->history($security, $activities, $enrichment, $publicOrigins)->history($request),
            default => new UserRecentController($security, $activities, $enrichment, $publicOrigins)->recent($request),
        };

        self::assertSame(
            'https://baander.app/api/images/' . $cover->getPublicId()->toString() . '/file',
            $this->data($response)[0]['coverImage']['url'],
        );
    }

    private function history(
        Security $security,
        ActivityPortInterface $activities,
        ActivityEnrichmentService $enrichment,
        PublicOrigins $publicOrigins,
    ): ActivityController {
        return new ActivityController(
            security: $security,
            activityService: $activities,
            commandBus: $this->createStub(MessageBusInterface::class),
            enrichmentService: $enrichment,
            songPort: $this->createStub(SongPortInterface::class),
            albumPort: $this->createStub(AlbumPortInterface::class),
            artistPort: $this->createStub(ArtistPortInterface::class),
            moviePort: $this->createStub(MoviePortInterface::class),
            identities: $this->identities(),
            publicOrigins: $publicOrigins,
        );
    }

    private function enrichment(SongPortInterface $songs, AlbumPortInterface $albums, ImagePortInterface $images): ActivityEnrichmentService
    {
        $scopes = $this->createStub(LibraryReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(LibraryReadScope::unrestricted());

        return new ActivityEnrichmentService(
            songService: $songs,
            albumService: $albums,
            imageService: $images,
            movieService: $this->createStub(MoviePortInterface::class),
            identities: $this->identities(),
            availability: $this->createStub(SongAvailabilityPortInterface::class),
            libraryScopes: $scopes,
        );
    }

    private function identities(): ActivityIdentityProjectionPortInterface
    {
        $identities = $this->createStub(ActivityIdentityProjectionPortInterface::class);
        $identities->method('userPublicId')->willReturn(new PublicId());

        return $identities;
    }

    private function principal(Uuid $id): AuthenticatedUserIdentityInterface&UserInterface
    {
        $user = $this->createStubForIntersectionOfInterfaces([AuthenticatedUserIdentityInterface::class, UserInterface::class]);
        $user->method('getId')->willReturn($id->toString());

        self::assertInstanceOf(UserInterface::class, $user);
        self::assertInstanceOf(AuthenticatedUserIdentityInterface::class, $user);

        return $user;
    }

    /** @return list<array<string, mixed>> */
    private function data(JsonResponse $response): array
    {
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
