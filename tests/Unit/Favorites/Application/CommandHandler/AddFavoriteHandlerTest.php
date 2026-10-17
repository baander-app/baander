<?php

declare(strict_types=1);

namespace App\Tests\Unit\Favorites\Application\CommandHandler;

use App\Favorites\Application\Command\AddFavoriteCommand;
use App\Favorites\Application\CommandHandler\AddFavoriteHandler;
use App\Favorites\Application\Port\FavoritesPortInterface;
use App\Favorites\Domain\Model\UserFavorite;
use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ValueError;

final class AddFavoriteHandlerTest extends TestCase
{
    private FavoritesPortInterface&Stub $favoritesPort;
    private AddFavoriteHandler $handler;

    protected function setUp(): void
    {
        $this->favoritesPort = $this->createStub(FavoritesPortInterface::class);
        $this->handler = $this->createAddFavoriteHandlerFixture();
    }

    private function createAddFavoriteHandlerFixture(): AddFavoriteHandler
    {
        $fixture = new AddFavoriteHandler($this->favoritesPort, $this->createStub(\App\Favorites\Application\Port\FavoriteTargetAccessInterface::class));
        return $fixture;
    }

    public function testAddsNewFavoriteWhenNoneExists(): void
    {
        $this->favoritesPort = $this->createMock(FavoritesPortInterface::class);
        $this->handler = $this->createAddFavoriteHandlerFixture();

        $userId = Uuid::v4();
        $newFavorite = UserFavorite::create($userId, FavoriteType::Song, 'song-pub-100000000000');

        $this->favoritesPort->expects($this->once())
            ->method('findByUserAndEntity')
            ->with($userId, FavoriteType::Song, 'song-pub-100000000000')
            ->willReturn(null);
        $this->favoritesPort->expects($this->once())
            ->method('addFavorite')
            ->with($userId, FavoriteType::Song, 'song-pub-100000000000')
            ->willReturn($newFavorite);

        $result = ($this->handler)(new AddFavoriteCommand($userId, 'song', 'song-pub-100000000000'));

        $this->assertSame($newFavorite, $result);
        $this->assertSame(FavoriteType::Song, $result->getEntityType());
    }

    public function testReturnsExistingFavoriteWhenAlreadyFavorited(): void
    {
        $this->favoritesPort = $this->createMock(FavoritesPortInterface::class);
        $this->handler = $this->createAddFavoriteHandlerFixture();

        $userId = Uuid::v4();
        $existing = UserFavorite::create($userId, FavoriteType::Album, 'album-pub-20000000000');

        $this->favoritesPort->expects($this->once())
            ->method('findByUserAndEntity')
            ->with($userId, FavoriteType::Album, 'album-pub-20000000000')
            ->willReturn($existing);
        $this->favoritesPort->expects($this->never())->method('addFavorite');

        $result = ($this->handler)(new AddFavoriteCommand($userId, 'album', 'album-pub-20000000000'));

        $this->assertSame($existing, $result);
    }

    public function testConvertsEntityTypeStringToEnum(): void
    {
        $userId = Uuid::v4();
        $favorite = UserFavorite::create($userId, FavoriteType::Artist, 'artist-pub-3000000000');

        $this->favoritesPort->method('findByUserAndEntity')->willReturn(null);
        $this->favoritesPort->method('addFavorite')->willReturn($favorite);

        $result = ($this->handler)(new AddFavoriteCommand($userId, 'artist', 'artist-pub-3000000000'));

        $this->assertSame(FavoriteType::Artist, $result->getEntityType());
    }

    public function testInternalUuidIsRejectedBeforeAnyTargetLookup(): void
    {
        $targets = $this->createMock(\App\Favorites\Application\Port\FavoriteTargetAccessInterface::class);
        $targets->expects(self::never())->method('assertVisible');
        $handler = new AddFavoriteHandler($this->favoritesPort, $targets);
        $this->expectException(\InvalidArgumentException::class);
        $handler(new AddFavoriteCommand(new Uuid(), 'song', (new Uuid())->toString()));
    }

    public function testTargetAccessIsCheckedBeforeReturningAnExistingFavorite(): void
    {
        $actorId = new Uuid();
        $publicId = new \App\Shared\Domain\Model\PublicId();
        $targets = $this->createMock(\App\Favorites\Application\Port\FavoriteTargetAccessInterface::class);
        $targets->expects(self::once())->method('assertVisible')->with($actorId, FavoriteType::Song, $publicId)
            ->willThrowException(new \OutOfBoundsException('Favorite target not found.'));
        $favorites = $this->createMock(FavoritesPortInterface::class);
        $favorites->expects(self::never())->method('findByUserAndEntity');
        $handler = new AddFavoriteHandler($favorites, $targets);
        $this->expectException(\OutOfBoundsException::class);
        $handler(new AddFavoriteCommand($actorId, 'song', $publicId->toString()));
    }

    public function testThrowsOnInvalidEntityType(): void
    {
        $this->favoritesPort = $this->createMock(FavoritesPortInterface::class);
        $this->handler = $this->createAddFavoriteHandlerFixture();

        $userId = Uuid::v4();

        $this->favoritesPort->expects($this->never())->method('findByUserAndEntity');
        $this->favoritesPort->expects($this->never())->method('addFavorite');

        $this->expectException(ValueError::class);

        ($this->handler)(new AddFavoriteCommand($userId, 'playlist', 'pub-1'));
    }
}
