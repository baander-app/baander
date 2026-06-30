<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Infrastructure\Security\Voter\LibraryVoter;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class LibraryVoterTest extends TestCase
{
    private LibraryVoter $voter;
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->voter = new LibraryVoter($this->connection);
    }

    private function createToken(string $userId, array $roles): TokenInterface&MockObject
    {
        $user = new SecurityUser($userId, 'user@example.com', 'hashed', $roles);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn($roles);

        return $token;
    }

    /**
     * Creates a library entity stub with real UUIDs so LibraryVoter can
     * pass them to LibraryAccessService::canAccessLibrary().
     */
    private function createLibraryEntity(string $ownerId, string $id = null): object
    {
        return new class($ownerId, $id ?? Uuid::v4()->toString()) {
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
        $token = $this->createToken(Uuid::v4()->toString(), ['ROLE_USER']);
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'library', [LibraryVoter::VIEW]));
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'library', [LibraryVoter::EDIT]));
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'library', [LibraryVoter::DELETE]));
    }

    public function testSupportsObjectSubject(): void
    {
        $entity = $this->createLibraryEntity(Uuid::v4()->toString());
        $token = $this->createToken(Uuid::v4()->toString(), ['ROLE_USER']);
        $this->assertNotEquals(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, $entity, [LibraryVoter::VIEW]));
    }

    public function testAbstainsOnUnknownAttribute(): void
    {
        $token = $this->createToken(Uuid::v4()->toString(), ['ROLE_USER']);
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'library', ['UNKNOWN']));
    }

    public function testAbstainsOnWrongStringSubject(): void
    {
        $token = $this->createToken(Uuid::v4()->toString(), ['ROLE_USER']);
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, 'album', [LibraryVoter::VIEW]));
    }

    // --- Entity-based voting ---

    public function testOwnerCanView(): void
    {
        $ownerId = Uuid::v4()->toString();
        $library = $this->createLibraryEntity($ownerId);
        $token = $this->createToken($ownerId, ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $library, [LibraryVoter::VIEW]));
    }

    public function testOwnerCanEdit(): void
    {
        $ownerId = Uuid::v4()->toString();
        $library = $this->createLibraryEntity($ownerId);
        $token = $this->createToken($ownerId, ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $library, [LibraryVoter::EDIT]));
    }

    public function testOwnerCanDelete(): void
    {
        $ownerId = Uuid::v4()->toString();
        $library = $this->createLibraryEntity($ownerId);
        $token = $this->createToken($ownerId, ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $library, [LibraryVoter::DELETE]));
    }

    public function testNonOwnerCannotViewWhenNoAccessGranted(): void
    {
        $ownerId = Uuid::v4()->toString();
        $nonOwnerId = Uuid::v4()->toString();
        $library = $this->createLibraryEntity($ownerId);
        $token = $this->createToken($nonOwnerId, ['ROLE_USER']);

        $this->connection->method('fetchOne')->willReturn(false);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $library, [LibraryVoter::VIEW]));
    }

    public function testNonOwnerCanViewWhenAccessGrantedByService(): void
    {
        $ownerId = Uuid::v4()->toString();
        $nonOwnerId = Uuid::v4()->toString();
        $library = $this->createLibraryEntity($ownerId);
        $token = $this->createToken($nonOwnerId, ['ROLE_USER']);

        $this->connection->method('fetchOne')->willReturn('1');

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $library, [LibraryVoter::VIEW]));
    }

    public function testNonOwnerCannotEditWhenNoAccessGranted(): void
    {
        $ownerId = Uuid::v4()->toString();
        $nonOwnerId = Uuid::v4()->toString();
        $library = $this->createLibraryEntity($ownerId);
        $token = $this->createToken($nonOwnerId, ['ROLE_USER']);

        $this->connection->method('fetchOne')->willReturn(false);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $library, [LibraryVoter::EDIT]));
    }

    public function testNonOwnerCannotDeleteEvenWithAccessGranted(): void
    {
        $ownerId = Uuid::v4()->toString();
        $nonOwnerId = Uuid::v4()->toString();
        $library = $this->createLibraryEntity($ownerId);
        $token = $this->createToken($nonOwnerId, ['ROLE_USER']);

        // Only owner can delete, access service result is irrelevant
        $this->connection->method('fetchOne')->willReturn('1');

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $library, [LibraryVoter::DELETE]));
    }

    // --- Admin override ---

    public function testAdminCanViewAnyLibrary(): void
    {
        $library = $this->createLibraryEntity(Uuid::v4()->toString());
        $token = $this->createToken(Uuid::v4()->toString(), ['ROLE_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $library, [LibraryVoter::VIEW]));
    }

    public function testAdminCanEditAnyLibrary(): void
    {
        $library = $this->createLibraryEntity(Uuid::v4()->toString());
        $token = $this->createToken(Uuid::v4()->toString(), ['ROLE_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $library, [LibraryVoter::EDIT]));
    }

    public function testAdminCanDeleteAnyLibrary(): void
    {
        $library = $this->createLibraryEntity(Uuid::v4()->toString());
        $token = $this->createToken(Uuid::v4()->toString(), ['ROLE_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $library, [LibraryVoter::DELETE]));
    }

    // --- String subject fallback (the fix) ---

    public function testStringSubjectDeniesView(): void
    {
        $token = $this->createToken(Uuid::v4()->toString(), ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'library', [LibraryVoter::VIEW]));
    }

    public function testStringSubjectDeniesEdit(): void
    {
        $token = $this->createToken(Uuid::v4()->toString(), ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'library', [LibraryVoter::EDIT]));
    }

    public function testStringSubjectDeniesDelete(): void
    {
        $token = $this->createToken(Uuid::v4()->toString(), ['ROLE_USER']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, 'library', [LibraryVoter::DELETE]));
    }

    // --- Edge cases ---

    public function testNonSecurityUserIsDenied(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $library = $this->createLibraryEntity(Uuid::v4()->toString());
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $library, [LibraryVoter::VIEW]));
    }
}
