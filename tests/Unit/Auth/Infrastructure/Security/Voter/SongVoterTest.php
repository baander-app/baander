<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\Voter\SongVoter;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class SongVoterTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, int}> */
    public static function resourceVotes(): iterable
    {
        foreach (['domain', 'orm', 'string'] as $kind) {
            foreach (['user', 'admin'] as $actor) {
                foreach (['VIEW', 'EDIT', 'DELETE'] as $attribute) {
                    $expected = $kind === 'string'
                        ? ($actor === 'admin' ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED)
                        : VoterInterface::ACCESS_ABSTAIN;
                    yield "$kind $actor $attribute" => [$kind, $actor, $attribute, $expected];
                }
            }
        }
    }

    #[DataProvider('resourceVotes')]
    public function testRealResourceAndExplicitStringPolicy(string $kind, string $actor, string $attribute, int $expected): void
    {
        $userId = Uuid::generate();
        $roles = $actor === 'admin' ? ['ROLE_ADMIN'] : ['ROLE_USER'];

        self::assertSame($expected, $this->voter()->vote($this->token($userId, $roles), $this->subject($kind), [$attribute]));
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function unrelatedVotes(): iterable
    {
        foreach (['duck', 'plain', 'null', 'other string'] as $kind) {
            foreach ([false, true] as $admin) {
                foreach (['VIEW', 'EDIT', 'DELETE'] as $attribute) {
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
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter()->vote($this->token(Uuid::generate(), ['ROLE_ADMIN']), 'song', ['UNKNOWN']));
    }

    public function testNonSecurityUserIsDeniedForSupportedSubject(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($token, 'song', ['VIEW']));
    }

    private function voter(): SongVoter
    {
        return new SongVoter();
    }

    private function subject(string $kind): object|string
    {
        return match ($kind) {
            'domain' => Song::create(Uuid::generate(), 'Song', '/music/song.mp3', 100, 'audio/mpeg'),
            'orm' => new SongEntity(new PublicId(), new AlbumEntity(new PublicId(), new LibraryEntity('Music', 'music', '/music', 'music', 'local'), 'Album', 'album'), 'Song', '/music/song.mp3', 100, 'audio/mpeg'),
            default => 'song',
        };
    }

    /** @param list<string> $roles */
    private function token(Uuid $userId, array $roles): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new SecurityUser($userId->toString(), 'user@baander.app', 'hashed', $roles));
        $token->method('getRoleNames')->willReturn($roles);
        return $token;
    }
}
