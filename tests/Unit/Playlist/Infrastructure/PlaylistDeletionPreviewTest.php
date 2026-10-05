<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Infrastructure;

use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Playlist\Infrastructure\PlaylistService;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class PlaylistDeletionPreviewTest extends TestCase
{
    public function testDeduplicatesByIdentityAcrossSongsAndPreservesSameNamePlaylists(): void
    {
        $firstSong = new Uuid();
        $secondSong = new Uuid();
        $sharedPlaylist = ['uuid' => (new Uuid())->toString(), 'name' => 'Favorites'];
        $otherPlaylist = ['uuid' => (new Uuid())->toString(), 'name' => 'Favorites'];

        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->expects(self::exactly(2))
            ->method('findPlaylistNamesContainingSong')
            ->willReturnCallback(
                static function (Uuid $songId) use ($firstSong, $secondSong, $sharedPlaylist, $otherPlaylist): array {
                    if ($songId->equals($firstSong)) {
                        return [$sharedPlaylist];
                    }

                    self::assertTrue($songId->equals($secondSong));

                    return [$sharedPlaylist, $otherPlaylist];
                },
            );

        $service = new PlaylistService($repository);

        self::assertSame(
            [$sharedPlaylist, $otherPlaylist],
            $service->findContainingSongs([$firstSong, $secondSong, $firstSong]),
        );
    }

    public function testEmptyAlbumDoesNotQueryPlaylists(): void
    {
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->expects(self::never())->method('findPlaylistNamesContainingSong');

        self::assertSame([], (new PlaylistService($repository))->findContainingSongs([]));
    }
}
