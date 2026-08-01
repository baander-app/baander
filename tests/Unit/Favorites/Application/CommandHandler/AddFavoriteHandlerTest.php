<?php

declare(strict_types=1);

namespace App\Tests\Unit\Favorites\Application\CommandHandler;

use App\Favorites\Application\Command\AddFavoriteCommand;
use App\Favorites\Application\CommandHandler\AddFavoriteHandler;
use App\Favorites\Application\Port\FavoritesPortInterface;
use App\Favorites\Domain\Model\UserFavorite;
use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ValueError;

final class AddFavoriteHandlerTest extends TestCase
{
    private FavoritesPortInterface&MockObject $favoritesPort;
    private AddFavoriteHandler $handler;

    protected function setUp(): void
    {
        $this->favoritesPort = $this->createMock(FavoritesPortInterface::class);
        $this->handler = new AddFavoriteHandler($this->favoritesPort);
    }

    public function testAddsNewFavoriteWhenNoneExists(): void
    {
        $userId = Uuid::v4();
        $newFavorite = UserFavorite::create($userId, FavoriteType::Song, 'song-pub-1');

        $this->favoritesPort->expects($this->once())
            ->method('findByUserAndEntity')
            ->with($userId, FavoriteType::Song, 'song-pub-1')
            ->willReturn(null);
        $this->favoritesPort->expects($this->once())
            ->method('addFavorite')
            ->with($userId, FavoriteType::Song, 'song-pub-1')
            ->willReturn($newFavorite);

        $result = ($this->handler)(new AddFavoriteCommand($userId, 'song', 'song-pub-1'));

        $this->assertSame($newFavorite, $result);
        $this->assertSame(FavoriteType::Song, $result->getEntityType());
    }

    public function testReturnsExistingFavoriteWhenAlreadyFavorited(): void
    {
        $userId = Uuid::v4();
        $existing = UserFavorite::create($userId, FavoriteType::Album, 'album-pub-2');

        $this->favoritesPort->expects($this->once())
            ->method('findByUserAndEntity')
            ->with($userId, FavoriteType::Album, 'album-pub-2')
            ->willReturn($existing);
        $this->favoritesPort->expects($this->never())->method('addFavorite');

        $result = ($this->handler)(new AddFavoriteCommand($userId, 'album', 'album-pub-2'));

        $this->assertSame($existing, $result);
    }

    public function testConvertsEntityTypeStringToEnum(): void
    {
        $userId = Uuid::v4();
        $favorite = UserFavorite::create($userId, FavoriteType::Artist, 'artist-pub-3');

        $this->favoritesPort->method('findByUserAndEntity')->willReturn(null);
        $this->favoritesPort->method('addFavorite')->willReturn($favorite);

        $result = ($this->handler)(new AddFavoriteCommand($userId, 'artist', 'artist-pub-3'));

        $this->assertSame(FavoriteType::Artist, $result->getEntityType());
    }

    public function testThrowsOnInvalidEntityType(): void
    {
        $userId = Uuid::v4();

        $this->favoritesPort->expects($this->never())->method('findByUserAndEntity');
        $this->favoritesPort->expects($this->never())->method('addFavorite');

        $this->expectException(ValueError::class);

        ($this->handler)(new AddFavoriteCommand($userId, 'playlist', 'pub-1'));
    }
}
