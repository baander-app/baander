<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Infrastructure\Security\Voter\AlbumVoter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class AlbumVoterTest extends TestCase
{
    private AlbumVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new AlbumVoter();
    }

    private function createToken(string $userId, array $roles): TokenInterface&MockObject
    {
        $user = new SecurityUser($userId, 'user@example.com', 'hashed', $roles);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn($roles);

        return $token;
    }

    private function createAlbumEntity(string $ownerId, string $id = 'album-id'): object
    {
        return new class($ownerId, $id) {
            public function __construct(
                private readonly string $ownerId,
                private readonly string $id,
            ) {
            }

            public function getOwnerId(): string
            {
                return $this->ownerId;
            }

            public function getId(): string
            {
                return $this->id;
            }
        };
    }

    // --- supports() ---

    public function testSupportsStringSubject(): void
    {
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'album', [AlbumVoter::VIEW]));
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'album', [AlbumVoter::EDIT]));
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'album', [AlbumVoter::DELETE]));
    }

    public function testSupportsObjectSubject(): void
    {
        $entity = $this->createAlbumEntity('user-1');
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, $entity, [AlbumVoter::VIEW]));
    }

    public function testAbstainsOnUnknownAttribute(): void
    {
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'album', ['UNKNOWN']));
    }

    public function testAbstainsOnWrongStringSubject(): void
    {
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'song', [AlbumVoter::VIEW]));
    }

    // --- Entity-based voting ---

    public function testOwnerCanView(): void
    {
        $album = $this->createAlbumEntity('user-123');
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $album, [AlbumVoter::VIEW]));
    }

    public function testOwnerCanEdit(): void
    {
        $album = $this->createAlbumEntity('user-123');
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $album, [AlbumVoter::EDIT]));
    }

    public function testOwnerCanDelete(): void
    {
        $album = $this->createAlbumEntity('user-123');
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $album, [AlbumVoter::DELETE]));
    }

    public function testNonOwnerCannotView(): void
    {
        $album = $this->createAlbumEntity('user-123');
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $album, [AlbumVoter::VIEW]));
    }

    public function testNonOwnerCannotEdit(): void
    {
        $album = $this->createAlbumEntity('user-123');
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $album, [AlbumVoter::EDIT]));
    }

    public function testNonOwnerCannotDelete(): void
    {
        $album = $this->createAlbumEntity('user-123');
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $album, [AlbumVoter::DELETE]));
    }

    // --- Admin override ---

    public function testAdminCanViewAnyAlbum(): void
    {
        $album = $this->createAlbumEntity('user-123');
        $token = $this->createToken('admin-1', ['ROLE_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $album, [AlbumVoter::VIEW]));
    }

    public function testAdminCanEditAnyAlbum(): void
    {
        $album = $this->createAlbumEntity('user-123');
        $token = $this->createToken('admin-1', ['ROLE_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $album, [AlbumVoter::EDIT]));
    }

    public function testAdminCanDeleteAnyAlbum(): void
    {
        $album = $this->createAlbumEntity('user-123');
        $token = $this->createToken('admin-1', ['ROLE_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $album, [AlbumVoter::DELETE]));
    }

    // --- String subject fallback (the fix) ---

    public function testStringSubjectDeniesView(): void
    {
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'album', [AlbumVoter::VIEW]));
    }

    public function testStringSubjectDeniesEdit(): void
    {
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'album', [AlbumVoter::EDIT]));
    }

    public function testStringSubjectDeniesDelete(): void
    {
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'album', [AlbumVoter::DELETE]));
    }

    // --- Edge cases ---

    public function testNonSecurityUserIsDenied(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $album = $this->createAlbumEntity('user-123');
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $album, [AlbumVoter::VIEW]));
    }
}
