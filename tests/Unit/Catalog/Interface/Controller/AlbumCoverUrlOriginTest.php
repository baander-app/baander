<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Controller;

use App\Catalog\Application\Port\AlbumDuplicatePortInterface;
use App\Catalog\Application\Port\AlbumMergePortInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongAvailabilityPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Interface\Controller\AlbumController;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Domain\Model\Image;
use App\Shared\Application\Http\PublicOrigins;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class AlbumCoverUrlOriginTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function entrypoints(): iterable
    {
        yield 'list' => ['index'];
        yield 'detail' => ['show'];
    }

    #[DataProvider('entrypoints')]
    public function testCoverUrlsStartWithThePublicOriginUnderAForgedHost(string $entrypoint): void
    {
        $cover = Image::create('/covers/cover.jpg', 'jpg', 'image/jpeg', 1, 1, 1, 'album');
        $album = Album::create(new Uuid(), 'Album', 'studio');
        $album->setCoverImage($cover->getId());
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('searchVisible')->willReturn(SearchResult::create([$album], 1));
        $albums->method('findVisibleByPublicId')->willReturn($album);
        $albums->method('findVisibleWithSongs')->willReturn([$album, []]);
        $images = $this->createStub(ImagePortInterface::class);
        $images->method('findByUuids')->willReturn([$cover->getId()->toString() => $cover]);
        $images->method('findByUuid')->willReturn($cover);
        $scopes = $this->createStub(LibraryReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(LibraryReadScope::unrestricted());
        $controller = new AlbumController(
            $albums,
            $scopes,
            $images,
            $this->createStub(AlbumDuplicatePortInterface::class),
            $this->createStub(AlbumMergePortInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(SongAvailabilityPortInterface::class),
            $this->createStub(AuthorizationCheckerInterface::class),
            new PublicOrigins('https://baander.app'),
        );
        $publicId = $album->getPublicId()->toString();

        $response = match ($entrypoint) {
            'index' => $controller->index(Request::create('https://other.baander.app/api/albums/')),
            default => $controller->show($publicId, Request::create('https://other.baander.app/api/albums/' . $publicId)),
        };

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(
            'https://baander.app/api/images/' . $cover->getPublicId()->toString() . '/file',
            ($entrypoint === 'index' ? $data[0] : $data)['coverImage']['url'],
        );
    }
}
