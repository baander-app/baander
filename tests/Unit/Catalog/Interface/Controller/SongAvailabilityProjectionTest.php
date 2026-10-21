<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Controller;

use App\Catalog\Application\Port\AlbumDuplicatePortInterface;
use App\Catalog\Application\Port\AlbumMergePortInterface;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongAvailabilityPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\SongState;
use App\Catalog\Interface\Controller\AlbumController;
use App\Catalog\Interface\Controller\SongController;
use App\Catalog\Interface\Request\UpdateSongRequest;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Shared\Domain\Model\CursorPage;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class SongAvailabilityProjectionTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function entrypoints(): iterable
    {
        yield 'list and search' => ['index'];
        yield 'detail' => ['show'];
        yield 'metadata edit' => ['update'];
        yield 'album tracks' => ['album'];
    }

    #[DataProvider('entrypoints')]
    public function testMusicResponsesRetainUnavailableSongsAndProjectTheBatchObservation(string $entrypoint): void
    {
        $album = Album::create(new Uuid(), 'Album', 'studio');
        $song = $this->song($album, 'Available');
        $unavailable = $this->song($album, 'Unavailable');
        $listed = in_array($entrypoint, ['index', 'album'], true) ? [$song, $unavailable] : [$song];
        $availability = $this->createMock(SongAvailabilityPortInterface::class);
        $availability->expects(self::once())->method('forSongs')->with($listed)->willReturn([
            $song->getId()->toString() => true,
            $unavailable->getId()->toString() => false,
        ]);
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findVisibleByPublicId')->willReturn($song);
        $songs->method('searchVisibleWithCursor')->willReturn(new CursorPage($listed, null, null, false, false, count($listed), false, 50));
        $scopes = $this->createStub(LibraryReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(LibraryReadScope::unrestricted());
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $command): Envelope => new Envelope($command, [new HandledStamp($song, 'song.update')]));

        if ($entrypoint === 'album') {
            $albums = $this->createStub(AlbumPortInterface::class);
            $albums->method('findVisibleByPublicId')->willReturn($album);
            $albums->method('findVisibleWithSongs')->willReturn([$album, $listed]);
            $controller = new AlbumController($albums, $scopes, $this->createStub(ImagePortInterface::class), $this->createStub(AlbumDuplicatePortInterface::class), $this->createStub(AlbumMergePortInterface::class), $bus, $availability);
            $response = $controller->show($album->getPublicId()->toString(), Request::create('https://baander.app/api/albums/' . $album->getPublicId()->toString()));
        } else {
            $controller = new SongController($songs, $scopes, new CursorCodec(new JsonEncoder()), $bus, $availability);
            $response = match ($entrypoint) {
                'index' => $controller->index(Request::create('https://baander.app/api/songs/?q=Song')),
                'show' => $controller->show($song->getPublicId()->toString()),
                default => $controller->update($song->getPublicId()->toString(), new UpdateSongRequest(title: 'Edited')),
            };
        }

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $projected = match ($entrypoint) {
            'index' => $data,
            'album' => $data['songs'],
            default => [$data],
        };
        self::assertSame(array_map(static fn (Song $song): string => $song->getPublicId()->toString(), $listed), array_column($projected, 'publicId'));
        self::assertSame(count($listed) === 2 ? [true, false] : [true], array_column($projected, 'available'));
    }

    public function testHiddenSongIsNotProjectedOrProbed(): void
    {
        $availability = $this->createMock(SongAvailabilityPortInterface::class);
        $availability->expects(self::never())->method('forSongs');
        $scopes = $this->createStub(LibraryReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(LibraryReadScope::none());
        $controller = new SongController($this->createStub(SongPortInterface::class), $scopes, new CursorCodec(new JsonEncoder()), $this->createStub(MessageBusInterface::class), $availability);

        self::assertSame(404, $controller->show((new \App\Shared\Domain\Model\PublicId())->toString())->getStatusCode());
    }

    private function song(Album $album, string $title): Song
    {
        $song = Song::create($album->getId(), $title, '/music/' . $title . '.flac', 1, 'audio/flac');
        return Song::reconstitute(new SongState(...array_replace(get_object_vars($song->getState()), ['albumPublicId' => $album->getPublicId()])));
    }
}
