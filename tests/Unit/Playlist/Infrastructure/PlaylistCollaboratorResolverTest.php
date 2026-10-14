<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Infrastructure;

use App\Auth\Application\Port\UserIdentifierResolverInterface;
use App\Playlist\Infrastructure\PlaylistCollaboratorResolver;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

/** The adapter resolves a public id only; a member route can never look a user up by email address. */
final class PlaylistCollaboratorResolverTest extends TestCase
{
    public function testAPublicIdResolvesThroughThePublicIdLookupOnly(): void
    {
        $publicId = new PublicId();
        $userId = Uuid::v4();
        $users = $this->createMock(UserIdentifierResolverInterface::class);
        $users->expects($this->once())->method('userIdByPublicId')->with($publicId)->willReturn($userId);
        $users->expects($this->never())->method('userId');

        $this->assertTrue((new PlaylistCollaboratorResolver($users))->userId($publicId)->equals($userId));
    }

    public function testAnUnknownPublicIdIsNotFound(): void
    {
        $publicId = new PublicId();
        $users = $this->createStub(UserIdentifierResolverInterface::class);
        $users->method('userIdByPublicId')->willThrowException(new NotFoundException(sprintf('User "%s" not found.', $publicId)));

        $this->expectException(NotFoundException::class);

        (new PlaylistCollaboratorResolver($users))->userId($publicId);
    }
}
