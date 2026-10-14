<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Infrastructure\Security;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Infrastructure\Security\PlaylistVoter;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class PlaylistVoterTest extends TestCase
{
    private const array ATTRIBUTES = ['VIEW', 'EDIT_SONGS', 'EDIT', 'DELETE', 'MANAGE_COLLABORATORS', 'LEAVE'];

    /**
     * The whole matrix: who gets what, with the collaborative flag on and off.
     *
     * @return iterable<string, array{string, bool, string, int}>
     */
    public static function matrix(): iterable
    {
        $granted = [
            'owner' => ['VIEW', 'EDIT_SONGS', 'EDIT', 'DELETE', 'MANAGE_COLLABORATORS'],
            'collaborator' => ['VIEW', 'EDIT_SONGS', 'LEAVE'],
            'collaborator flag off' => ['VIEW', 'LEAVE'],
            'admin' => ['VIEW'],
            'unrelated' => [],
        ];
        foreach ($granted as $actor => $grants) {
            $collaborative = $actor !== 'collaborator flag off';
            foreach (self::ATTRIBUTES as $attribute) {
                $expected = in_array($attribute, $grants, true) ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED;
                yield "$actor $attribute" => [$actor, $collaborative, $attribute, $expected];
            }
        }
    }

    #[DataProvider('matrix')]
    public function testMatrix(string $actor, bool $collaborative, string $attribute, int $expected): void
    {
        $ownerId = Uuid::generate();
        $collaboratorId = Uuid::generate();
        $playlist = $this->playlist($ownerId, [$collaboratorId], $collaborative);
        $userId = match ($actor) {
            'owner' => $ownerId,
            'collaborator', 'collaborator flag off' => $collaboratorId,
            default => Uuid::generate(),
        };
        $roles = $actor === 'admin' ? ['ROLE_ADMIN'] : ['ROLE_USER'];

        self::assertSame($expected, $this->voter()->vote($this->token($userId, $roles), $playlist, [$attribute]));
    }

    public function testAnAdministratorWhoCollaboratesEditsSongsAsACollaborator(): void
    {
        $adminId = Uuid::generate();
        $token = $this->token($adminId, ['ROLE_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter()->vote($token, $this->playlist(Uuid::generate(), [$adminId], true), ['EDIT_SONGS']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($token, $this->playlist(Uuid::generate(), [$adminId], false), ['EDIT_SONGS']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($token, $this->playlist(Uuid::generate(), [$adminId], true), ['EDIT']));
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function unrelatedVotes(): iterable
    {
        foreach (['duck', 'plain', 'null', 'string'] as $kind) {
            foreach ([false, true] as $admin) {
                foreach (self::ATTRIBUTES as $attribute) {
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
                public function isCollaborator(Uuid $userId): bool { return true; }
                public function isCollaborative(): bool { return true; }
            },
            'plain' => new \stdClass(),
            'null' => null,
            default => 'playlist',
        };

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter()->vote($this->token($userId, $admin ? ['ROLE_ADMIN'] : ['ROLE_USER']), $subject, [$attribute]));
    }

    public function testUnknownAttributeAbstains(): void
    {
        $ownerId = Uuid::generate();
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter()->vote($this->token($ownerId, ['ROLE_ADMIN']), $this->playlist($ownerId, [], true), ['UNKNOWN']));
    }

    public function testPrincipalWithoutIdentityContractIsDeniedEvenWithAdminRole(): void
    {
        foreach ([null, $this->createStub(UserInterface::class)] as $principal) {
            $token = $this->createStub(TokenInterface::class);
            $token->method('getUser')->willReturn($principal);
            $token->method('getRoleNames')->willReturn(['ROLE_ADMIN']);

            self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($token, $this->playlist(Uuid::generate(), [], true), ['VIEW']));
        }
    }

    private function voter(): PlaylistVoter
    {
        return new PlaylistVoter();
    }

    /** @param list<Uuid> $collaboratorIds */
    private function playlist(Uuid $ownerId, array $collaboratorIds, bool $collaborative): Playlist
    {
        return Playlist::reconstitute(
            id: Uuid::generate(),
            publicId: new PublicId(),
            userId: $ownerId,
            name: 'Playlist',
            description: null,
            isPublic: false,
            isCollaborative: $collaborative,
            isSmart: false,
            smartRules: [],
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            collaboratorIds: $collaboratorIds,
        );
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
