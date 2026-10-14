<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Playlist\Application\Command\AddCollaboratorCommand;
use App\Playlist\Application\Command\RemoveCollaboratorCommand;
use App\Playlist\Application\Port\PlaylistCollaboratorResolverInterface;
use App\Playlist\Application\Port\PlaylistPortInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Interface\Controller\PlaylistController;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The collaborator routes ask the voter before any lookup: the owner manages the rows,
 * a collaborator naming their own public id leaves, and everyone else is refused with
 * the attribute's message.
 */
final class PlaylistCollaboratorRoutesTest extends TestCase
{
    private Playlist $playlist;
    private Uuid $ownerId;
    private Uuid $collaboratorId;
    private PublicId $collaboratorPublicId;
    private Security&MockObject $security;
    private MessageBusInterface&MockObject $bus;
    private PlaylistCollaboratorResolverInterface&MockObject $users;
    private PlaylistController $controller;

    protected function setUp(): void
    {
        $this->ownerId = Uuid::v4();
        $this->collaboratorId = Uuid::v4();
        $this->collaboratorPublicId = new PublicId();
        $this->playlist = Playlist::create('Shared', $this->ownerId, isCollaborative: true);
        $this->playlist->addCollaborator($this->collaboratorId);

        $this->security = $this->createMock(Security::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->users = $this->createMock(PlaylistCollaboratorResolverInterface::class);
        $playlists = $this->createStub(PlaylistPortInterface::class);
        $playlists->method('findByPublicId')->willReturnCallback(
            fn (PublicId $publicId): ?Playlist => $publicId->equals($this->playlist->getPublicId()) ? $this->playlist : null,
        );
        $this->controller = new PlaylistController(
            $this->security,
            $playlists,
            $this->createStub(SongPortInterface::class),
            $this->bus,
            $this->createStub(LibraryReadScopeProviderInterface::class),
            $this->users,
        );
    }

    public function testTheOwnerAddsACollaboratorAfterTheVoterAdmitsThem(): void
    {
        $this->actingAs($this->ownerId);
        $this->security->expects($this->once())->method('isGranted')->with('MANAGE_COLLABORATORS', $this->playlist)->willReturn(true);
        $this->users->expects($this->never())->method('userId');
        $this->bus->expects($this->once())->method('dispatch')
            ->with($this->callback(fn (AddCollaboratorCommand $command): bool => $command->getPlaylistId()->equals($this->playlist->getId())
                && $command->getUserPublicId()->equals($this->collaboratorPublicId)))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $response = $this->controller->addCollaborator($this->playlist->getPublicId()->toString(), $this->collaboratorPublicId->toString());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testANonOwnerIsRefusedTheAdditionBeforeAnyLookup(): void
    {
        $this->actingAs($this->collaboratorId);
        $this->security->expects($this->once())->method('isGranted')->with('MANAGE_COLLABORATORS', $this->playlist)->willReturn(false);
        $this->users->expects($this->never())->method('userId');
        $this->bus->expects($this->never())->method('dispatch');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage(PlaylistController::REFUSAL_MESSAGES['MANAGE_COLLABORATORS']);

        $this->controller->addCollaborator($this->playlist->getPublicId()->toString(), (new PublicId())->toString());
    }

    public function testTheOwnerRemovesAnyRowWithoutResolvingThePathInTheController(): void
    {
        $this->actingAs($this->ownerId);
        $this->security->expects($this->once())->method('isGranted')->with('MANAGE_COLLABORATORS', $this->playlist)->willReturn(true);
        $this->users->expects($this->never())->method('userId');
        $this->bus->expects($this->once())->method('dispatch')
            ->with($this->callback(fn (RemoveCollaboratorCommand $command): bool => $command->getUserPublicId()->equals($this->collaboratorPublicId)))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $response = $this->controller->removeCollaborator($this->playlist->getPublicId()->toString(), $this->collaboratorPublicId->toString());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testACollaboratorNamingTheirOwnPublicIdLeavesThroughTheLeaveAttribute(): void
    {
        $this->actingAs($this->collaboratorId);
        $this->security->expects($this->exactly(2))->method('isGranted')
            ->willReturnCallback(fn (string $attribute, Playlist $subject): bool => match ($attribute) {
                'MANAGE_COLLABORATORS' => false,
                'LEAVE' => $subject === $this->playlist,
                default => throw new \LogicException(sprintf('Unexpected attribute %s.', $attribute)),
            });
        $this->users->expects($this->once())->method('userId')->with($this->collaboratorPublicId)->willReturn($this->collaboratorId);
        $this->bus->expects($this->once())->method('dispatch')
            ->with($this->isInstanceOf(RemoveCollaboratorCommand::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $response = $this->controller->removeCollaborator($this->playlist->getPublicId()->toString(), $this->collaboratorPublicId->toString());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testAUserWhoIsNoCollaboratorHasNothingToLeaveAndNothingIsWritten(): void
    {
        $stranger = Uuid::v4();
        $strangerPublicId = new PublicId();
        $this->actingAs($stranger);
        $this->security->expects($this->once())->method('isGranted')->with('MANAGE_COLLABORATORS', $this->playlist)->willReturn(false);
        $this->users->expects($this->once())->method('userId')->with($strangerPublicId)->willReturn($stranger);
        $this->bus->expects($this->never())->method('dispatch');

        $response = $this->controller->removeCollaborator($this->playlist->getPublicId()->toString(), $strangerPublicId->toString());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testANonOwnerNamingSomeoneElseIsRefusedAsManagement(): void
    {
        $this->actingAs($this->collaboratorId);
        $this->security->expects($this->once())->method('isGranted')->with('MANAGE_COLLABORATORS', $this->playlist)->willReturn(false);
        $other = new PublicId();
        $this->users->expects($this->once())->method('userId')->with($other)->willReturn(Uuid::v4());
        $this->bus->expects($this->never())->method('dispatch');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage(PlaylistController::REFUSAL_MESSAGES['MANAGE_COLLABORATORS']);

        $this->controller->removeCollaborator($this->playlist->getPublicId()->toString(), $other->toString());
    }

    public function testANonOwnerNamingAnUnknownUserIsNotFound(): void
    {
        $this->actingAs($this->collaboratorId);
        $this->security->expects($this->once())->method('isGranted')->with('MANAGE_COLLABORATORS', $this->playlist)->willReturn(false);
        $unknown = new PublicId();
        $this->users->expects($this->once())->method('userId')->with($unknown)->willThrowException(new NotFoundException('User not found.'));
        $this->bus->expects($this->never())->method('dispatch');

        $this->expectException(NotFoundException::class);

        $this->controller->removeCollaborator($this->playlist->getPublicId()->toString(), $unknown->toString());
    }

    public function testAnUnknownPlaylistIsNotFoundBeforeTheVoterIsAsked(): void
    {
        $this->actingAs($this->ownerId);
        $this->security->expects($this->never())->method('isGranted');
        $this->users->expects($this->never())->method('userId');
        $this->bus->expects($this->never())->method('dispatch');

        self::assertSame(404, $this->controller->addCollaborator((new PublicId())->toString(), $this->collaboratorPublicId->toString())->getStatusCode());
        self::assertSame(404, $this->controller->removeCollaborator((new PublicId())->toString(), $this->collaboratorPublicId->toString())->getStatusCode());
    }

    private function actingAs(Uuid $userId): void
    {
        $identity = new class($userId->toString()) implements UserInterface, AuthenticatedUserIdentityInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return $this->id;
            }
        };
        $this->security->method('getUser')->willReturn($identity);
    }
}
