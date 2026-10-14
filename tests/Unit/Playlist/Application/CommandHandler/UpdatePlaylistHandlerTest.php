<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Application\CommandHandler;

use App\Playlist\Application\Command\UpdatePlaylistCommand;
use App\Playlist\Application\CommandHandler\UpdatePlaylistHandler;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

/** The metadata update carries the owner's collaboration switch with the other fields. */
final class UpdatePlaylistHandlerTest extends TestCase
{
    public function testTheOwnerTurnsCollaborationOnWithTheMetadataUpdate(): void
    {
        $playlist = Playlist::create('Mix', Uuid::v4(), isCollaborative: false);
        $repository = $this->createMock(PlaylistRepositoryInterface::class);
        $repository->expects($this->once())->method('findByUuid')->with($playlist->getId())->willReturn($playlist);
        $repository->expects($this->once())->method('save')->with($playlist);

        $result = (new UpdatePlaylistHandler($repository))(new UpdatePlaylistCommand(
            playlistId: $playlist->getId(),
            name: 'Shared mix',
            description: 'Open to friends',
            isPublic: false,
            isCollaborative: true,
        ));

        $this->assertSame($playlist, $result);
        $this->assertSame('Shared mix', $playlist->getName());
        $this->assertSame('Open to friends', $playlist->getDescription());
        $this->assertFalse($playlist->isPublic());
        $this->assertTrue($playlist->isCollaborative());
    }

    public function testTheOwnerTurnsCollaborationOffWithoutLosingTheCollaborators(): void
    {
        $playlist = Playlist::create('Mix', Uuid::v4(), isCollaborative: true);
        $collaborator = Uuid::v4();
        $playlist->addCollaborator($collaborator);
        $repository = $this->createStub(PlaylistRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($playlist);

        (new UpdatePlaylistHandler($repository))(new UpdatePlaylistCommand(
            playlistId: $playlist->getId(),
            name: 'Mix',
            description: null,
            isPublic: false,
            isCollaborative: false,
        ));

        $this->assertFalse($playlist->isCollaborative());
        $this->assertTrue($playlist->isCollaborator($collaborator));
    }
}
