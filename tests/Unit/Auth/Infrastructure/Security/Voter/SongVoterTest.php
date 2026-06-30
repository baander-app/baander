<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Infrastructure\Security\Voter\SongVoter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class SongVoterTest extends TestCase
{
    private SongVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new SongVoter();
    }

    private function createToken(string $userId, array $roles): TokenInterface&MockObject
    {
        $user = new SecurityUser($userId, 'user@example.com', 'hashed', $roles);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn($roles);

        return $token;
    }

    private function createSongEntity(string $ownerId, string $id = 'song-id'): object
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
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'song', [SongVoter::VIEW]));
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'song', [SongVoter::EDIT]));
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'song', [SongVoter::DELETE]));
    }

    public function testSupportsObjectSubject(): void
    {
        $entity = $this->createSongEntity('user-1');
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, $entity, [SongVoter::VIEW]));
    }

    public function testAbstainsOnUnknownAttribute(): void
    {
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'song', ['UNKNOWN']));
    }

    public function testAbstainsOnWrongStringSubject(): void
    {
        $token = $this->createToken('user-1', ['ROLE_USER']);
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'album', [SongVoter::VIEW]));
    }

    // --- Entity-based voting ---

    public function testOwnerCanView(): void
    {
        $song = $this->createSongEntity('user-123');
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $song, [SongVoter::VIEW]));
    }

    public function testOwnerCanEdit(): void
    {
        $song = $this->createSongEntity('user-123');
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $song, [SongVoter::EDIT]));
    }

    public function testOwnerCanDelete(): void
    {
        $song = $this->createSongEntity('user-123');
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $song, [SongVoter::DELETE]));
    }

    public function testNonOwnerCannotView(): void
    {
        $song = $this->createSongEntity('user-123');
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $song, [SongVoter::VIEW]));
    }

    public function testNonOwnerCannotEdit(): void
    {
        $song = $this->createSongEntity('user-123');
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $song, [SongVoter::EDIT]));
    }

    public function testNonOwnerCannotDelete(): void
    {
        $song = $this->createSongEntity('user-123');
        $token = $this->createToken('user-456', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $song, [SongVoter::DELETE]));
    }

    // --- Admin override ---

    public function testAdminCanViewAnySong(): void
    {
        $song = $this->createSongEntity('user-123');
        $token = $this->createToken('admin-1', ['ROLE_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $song, [SongVoter::VIEW]));
    }

    public function testAdminCanEditAnySong(): void
    {
        $song = $this->createSongEntity('user-123');
        $token = $this->createToken('admin-1', ['ROLE_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $song, [SongVoter::EDIT]));
    }

    public function testAdminCanDeleteAnySong(): void
    {
        $song = $this->createSongEntity('user-123');
        $token = $this->createToken('admin-1', ['ROLE_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $song, [SongVoter::DELETE]));
    }

    // --- String subject fallback (the fix) ---

    public function testStringSubjectDeniesView(): void
    {
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'song', [SongVoter::VIEW]));
    }

    public function testStringSubjectDeniesEdit(): void
    {
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'song', [SongVoter::EDIT]));
    }

    public function testStringSubjectDeniesDelete(): void
    {
        $token = $this->createToken('user-123', ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'song', [SongVoter::DELETE]));
    }

    // --- Edge cases ---

    public function testNonSecurityUserIsDenied(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $song = $this->createSongEntity('user-123');
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $song, [SongVoter::VIEW]));
    }
}
