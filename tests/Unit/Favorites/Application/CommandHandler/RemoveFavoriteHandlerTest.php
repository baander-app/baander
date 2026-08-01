<?php

declare(strict_types=1);

namespace App\Tests\Unit\Favorites\Application\CommandHandler;

use App\Favorites\Application\Command\RemoveFavoriteCommand;
use App\Favorites\Application\CommandHandler\RemoveFavoriteHandler;
use App\Favorites\Application\Port\FavoritesPortInterface;
use App\Favorites\Domain\Model\UserFavorite;
use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RemoveFavoriteHandlerTest extends TestCase
{
    private FavoritesPortInterface&MockObject $favoritesPort;
    private RemoveFavoriteHandler $handler;

    protected function setUp(): void
    {
        $this->favoritesPort = $this->createMock(FavoritesPortInterface::class);
        $this->handler = new RemoveFavoriteHandler($this->favoritesPort);
    }

    public function testRemovesFavoriteWhenFoundAndOwnedByUser(): void
    {
        $userId = Uuid::v4();
        $publicId = new PublicId();
        $favorite = UserFavorite::create($userId, FavoriteType::Song, 'song-pub-1');

        $this->favoritesPort->expects($this->once())
            ->method('findByPublicId')
            ->with($publicId)
            ->willReturn($favorite);
        $this->favoritesPort->expects($this->once())
            ->method('removeFavorite')
            ->with($favorite);

        ($this->handler)(new RemoveFavoriteCommand($userId, $publicId));
    }

    public function testDoesNothingWhenFavoriteNotFound(): void
    {
        $publicId = new PublicId();

        $this->favoritesPort->expects($this->once())
            ->method('findByPublicId')
            ->with($publicId)
            ->willReturn(null);
        $this->favoritesPort->expects($this->never())->method('removeFavorite');

        ($this->handler)(new RemoveFavoriteCommand(Uuid::v4(), $publicId));
    }

    public function testDoesNothingWhenUserIdDoesNotMatch(): void
    {
        $ownerId = Uuid::v4();
        $otherUserId = Uuid::v4();
        $publicId = new PublicId();
        $favorite = UserFavorite::create($ownerId, FavoriteType::Album, 'album-pub-2');

        $this->favoritesPort->expects($this->once())
            ->method('findByPublicId')
            ->with($publicId)
            ->willReturn($favorite);
        $this->favoritesPort->expects($this->never())->method('removeFavorite');

        ($this->handler)(new RemoveFavoriteCommand($otherUserId, $publicId));
    }
}
