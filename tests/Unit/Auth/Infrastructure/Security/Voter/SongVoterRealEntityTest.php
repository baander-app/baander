<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Infrastructure\Security\Voter\SongVoter;
use App\Catalog\Domain\Model\Song;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Security-focused test for SongVoter against the real Song aggregate.
 *
 * SongVoter expects subjects to expose getOwnerId(), but the real Song domain
 * model does not. This causes the voter to fall through and deny access for
 * every real Song, breaking authorization for SongController update/delete.
 */
final class SongVoterRealEntityTest extends TestCase
{
    public function testVoterDoesNotDenyByDefaultForRealSongEntity(): void
    {
        $ownerId = Uuid::v4()->toString();
        $song = Song::create(
            album: Uuid::v4(),
            title: 'Test Song',
            path: '/music/test.mp3',
            size: 1000,
            mimeType: 'audio/mpeg',
        );

        $user = new SecurityUser($ownerId, 'user@example.com', 'hashed', ['ROLE_USER']);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn(['ROLE_USER']);

        $voter = new SongVoter();
        $result = $voter->vote($token, $song, [SongVoter::EDIT]);

        // Without ownership information the voter cannot make an access
        // decision. Denying by default makes the voter unusable for the real
        // Song aggregate and leaves SongController endpoints unprotected.
        $this->assertNotSame(VoterInterface::ACCESS_DENIED, $result);
    }
}
