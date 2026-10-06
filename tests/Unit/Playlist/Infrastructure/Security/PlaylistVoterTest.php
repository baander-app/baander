<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Infrastructure\Security;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Playlist\Infrastructure\Security\PlaylistVoter;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class PlaylistVoterTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, int}> */
    public static function resourceVotes(): iterable
    {
        foreach (['domain', 'orm', 'string'] as $kind) {
            foreach (['owner', 'other', 'admin'] as $actor) {
                foreach (['VIEW', 'EDIT', 'DELETE', 'MANAGE_COLLABORATORS'] as $attribute) {
                    $expected = (($kind !== 'string' && $actor === 'owner') || $actor === 'admin') ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED;
                    yield "$kind $actor $attribute" => [$kind, $actor, $attribute, $expected];
                }
            }
        }
    }

    #[DataProvider('resourceVotes')]
    public function testRealResourceAndExplicitStringPolicy(string $kind, string $actor, string $attribute, int $expected): void
    {
        $ownerId = Uuid::generate();
        $userId = $actor === 'owner' ? $ownerId : Uuid::generate();
        $roles = $actor === 'admin' ? ['ROLE_ADMIN'] : ['ROLE_USER'];

        self::assertSame($expected, $this->voter()->vote($this->token($userId, $roles), $this->subject($kind, $ownerId), [$attribute]));
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function unrelatedVotes(): iterable
    {
        foreach (['duck', 'plain', 'null', 'other string'] as $kind) {
            foreach ([false, true] as $admin) {
                foreach (['VIEW', 'EDIT', 'DELETE', 'MANAGE_COLLABORATORS'] as $attribute) {
                    yield $kind . ($admin ? ' admin ' : ' ordinary ') . $attribute => [$kind, $admin, $attribute];
                }
            }
        }
    }

    #[DataProvider('unrelatedVotes')]
    public function testUnrelatedSubjectsAbstainEvenForAdmins(string $kind, bool $admin, string $attribute): void
    {
        $userId = Uuid::generate();
        $subject = match ($kind) {
            'duck' => new class($userId) {
                public function __construct(private readonly Uuid $ownerId) {}
                public function getOwnerId(): string { return $this->ownerId->toString(); }
                public function getUserId(): Uuid { return $this->ownerId; }
                public function getId(): Uuid { return Uuid::generate(); }
                public function isCollaborator(string $userId): bool { return true; }
            },
            'plain' => new \stdClass(),
            'null' => null,
            default => 'unrelated',
        };

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter()->vote($this->token($userId, $admin ? ['ROLE_ADMIN'] : ['ROLE_USER']), $subject, [$attribute]));
    }

    public function testUnknownAttributeAbstains(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter()->vote($this->token(Uuid::generate(), ['ROLE_ADMIN']), 'playlist', ['UNKNOWN']));
    }

    public function testPrincipalWithoutIdentityContractIsDeniedEvenWithAdminRole(): void
    {
        foreach ([null, $this->createStub(UserInterface::class)] as $principal) {
            $token = $this->createStub(TokenInterface::class);
            $token->method('getUser')->willReturn($principal);
            $token->method('getRoleNames')->willReturn(['ROLE_ADMIN']);

            self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($token, 'playlist', ['VIEW']));
        }
    }

    private function voter(): PlaylistVoter
    {
        return new PlaylistVoter();
    }

    private function subject(string $kind, Uuid $ownerId): object|string
    {
        return match ($kind) {
            'domain' => Playlist::create('Playlist', $ownerId, isPublic: true, isCollaborative: true),
            'orm' => new PlaylistEntity(new PublicId(), new UserEntity(new PublicId(), 'Owner', 'owner@baander.app', 'hashed', '', $ownerId), 'Playlist'),
            default => 'playlist',
        };
    }

    /** @param list<string> $roles */
    private function token(Uuid $userId, array $roles): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $user = $this->createStubForIntersectionOfInterfaces([UserInterface::class, AuthenticatedUserIdentityInterface::class]);
        $user->method('getId')->willReturn($userId->toString());
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn($roles);
        return $token;
    }
}
