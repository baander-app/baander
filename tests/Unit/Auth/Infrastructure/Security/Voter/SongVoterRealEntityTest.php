<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\Voter;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Infrastructure\Security\Voter\SongVoter;
use App\Catalog\Domain\Model\Song;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class SongVoterRealEntityTest extends TestCase
{
    public function testRealSongWithoutOwnershipMetadataRemainsOutsideVoterContract(): void
    {
        $song = Song::create(Uuid::generate(), 'Song', '/music/song.mp3', 1000, 'audio/mpeg');
        $voter = new SongVoter();
        foreach ([['ROLE_USER'], ['ROLE_ADMIN']] as $roles) {
            $token = $this->createStub(TokenInterface::class);
            $token->method('getUser')->willReturn(new SecurityUser(Uuid::generate()->toString(), 'user@baander.app', 'hashed', $roles));
            $token->method('getRoleNames')->willReturn($roles);
            foreach ([SongVoter::VIEW, SongVoter::EDIT, SongVoter::DELETE] as $attribute) {
                self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $song, [$attribute]));
            }
        }
    }
}
