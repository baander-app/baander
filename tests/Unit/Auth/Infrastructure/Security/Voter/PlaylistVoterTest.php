<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Infrastructure\Security\Voter\PlaylistVoter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class PlaylistVoterTest extends TestCase
{
    private PlaylistVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new PlaylistVoter();
    }

    private function createToken(string $userId, array $roles): TokenInterface&MockObject
    {
        $user = new SecurityUser($userId, 'user@example.com', 'hashed', $roles);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn($roles);

        return $token;
    }

    private function createPlaylistEntity(string $ownerId, array $collaborators = [], string $id = 'playlist-id'): object
    {
        return new class($ownerId, $collaborators, $id) {
            public function __construct(
                private readonly string $ownerId,
                private readonly array $collaborators,
                private readonly string $id,
            ) {
            }

            public function getUserId(): object
            {
                return new class($this->ownerId) {
                    public function __construct(private readonly string $ownerId) {}
                    public function toString(): string { return $this->ownerId; }
                };
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function isCollaborator(string $userId): bool
            {
                return in_array($userId, $this->collaborators, true);
            }
        };
    }

    // --- supports() ---

    public function testSupportsStringSubject(): void
    {
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'playlist', [PlaylistVoter::VIEW]));
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'playlist', [PlaylistVoter::EDIT]));
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'playlist', [PlaylistVoter::DELETE]));
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'playlist', [PlaylistVoter::MANAGE_COLLABORATORS]));
    }

    public function testSupportsObjectSubject(): void
    {
        $entity = $this->createPlaylistEntity('user-1');
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, $entity, [PlaylistVoter::VIEW]));
    }

    public function testAbstainsOnUnknownAttribute(): void
    {
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'playlist', ['UNKNOWN']));
    }

    public function testAbstainsOnWrongStringSubject(): void
    {
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'album', [PlaylistVoter::VIEW]));
    }

    // --- Entity-based voting: owner ---

    public function testOwnerCanView(): void
    {
        $playlist = $this->createPlaylistEntity('user-123');
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $playlist, [PlaylistVoter::VIEW]));
    }

    public function testOwnerCanEdit(): void
    {
        $playlist = $this->createPlaylistEntity('user-123');
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $playlist, [PlaylistVoter::EDIT]));
    }

    public function testOwnerCanDelete(): void
    {
        $playlist = $this->createPlaylistEntity('user-123');
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $playlist, [PlaylistVoter::DELETE]));
    }

    public function testOwnerCanManageCollaborators(): void
    {
        $playlist = $this->createPlaylistEntity('user-123');
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $playlist, [PlaylistVoter::MANAGE_COLLABORATORS]));
    }

    // --- Entity-based voting: collaborator ---

    public function testCollaboratorCanView(): void
    {
        $playlist = $this->createPlaylistEntity('user-123', ['user-456']);
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $playlist, [PlaylistVoter::VIEW]));
    }

    public function testCollaboratorCanEdit(): void
    {
        $playlist = $this->createPlaylistEntity('user-123', ['user-456']);
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $playlist, [PlaylistVoter::EDIT]));
    }

    public function testCollaboratorCannotDelete(): void
    {
        $playlist = $this->createPlaylistEntity('user-123', ['user-456']);
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $playlist, [PlaylistVoter::DELETE]));
    }

    public function testCollaboratorCannotManageCollaborators(): void
    {
        $playlist = $this->createPlaylistEntity('user-123', ['user-456']);
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $playlist, [PlaylistVoter::MANAGE_COLLABORATORS]));
    }

    // --- Entity-based voting: non-owner, non-collaborator ---

    public function testNonOwnerNonCollaboratorCannotView(): void
    {
        $playlist = $this->createPlaylistEntity('user-123');
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $playlist, [PlaylistVoter::VIEW]));
    }

    public function testNonOwnerNonCollaboratorCannotEdit(): void
    {
        $playlist = $this->createPlaylistEntity('user-123');
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $playlist, [PlaylistVoter::EDIT]));
    }

    // --- Admin override ---

    public function testAdminCanViewAnyPlaylist(): void
    {
        $playlist = $this->createPlaylistEntity('user-123');
        $token = $this->createToken('admin-1', ['ROLE_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $playlist, [PlaylistVoter::VIEW]));
    }

    public function testAdminCanManageCollaborators(): void
    {
        $playlist = $this->createPlaylistEntity('user-123');
        $token = $this->createToken('admin-1', ['ROLE_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $playlist, [PlaylistVoter::MANAGE_COLLABORATORS]));
    }

    public function testAdminCanDeleteAnyPlaylist(): void
    {
        $playlist = $this->createPlaylistEntity('user-123');
        $token = $this->createToken('admin-1', ['ROLE_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $playlist, [PlaylistVoter::DELETE]));
    }

    // --- String subject fallback (the fix) ---

    public function testStringSubjectDeniesView(): void
    {
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'playlist', [PlaylistVoter::VIEW]));
    }

    public function testStringSubjectDeniesEdit(): void
    {
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'playlist', [PlaylistVoter::EDIT]));
    }

    public function testStringSubjectDeniesDelete(): void
    {
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'playlist', [PlaylistVoter::DELETE]));
    }

    public function testStringSubjectDeniesManageCollaborators(): void
    {
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'playlist', [PlaylistVoter::MANAGE_COLLABORATORS]));
    }

    // --- Edge cases ---

    public function testNonSecurityUserIsDenied(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $playlist = $this->createPlaylistEntity('user-123');
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $playlist, [PlaylistVoter::VIEW]));
    }
}
