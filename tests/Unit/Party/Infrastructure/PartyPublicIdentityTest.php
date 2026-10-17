<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Infrastructure;

use App\Auth\Domain\Model\User;
use App\Catalog\Domain\Model\Video;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Infrastructure\PartyPublicIdentity;
use App\Party\Interface\Resource\PartyMemberResource;
use App\Party\Interface\Resource\PartySessionResource;
use App\Shared\Domain\Model\Email;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\QualityTier;
use PHPUnit\Framework\TestCase;

final class PartyPublicIdentityTest extends TestCase
{
    public function testPublicReferencesResolveToInternalCommandsAndNestedOutputContainsNoInternalIds(): void
    {
        $user = User::register(Email::fromString('host@baander.app'), 'hash', 'Host');
        $video = Video::create('/media/party.mp4', 'video-hash');
        $job = TranscodeJob::create($video->getId(), QualityTier::p720(), '/tmp/baander-party-identity', $user->getId());
        $session = SyncedPartySession::create($user->getId(), $video->getId(), $job->getId());
        $member = PartyMember::create($user->getId(), $session->getId());
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $rows = [
            'users' => [$user->getId()->toString(), $user->getPublicId()->toString()],
            'videos' => [$video->getId()->toString(), $video->getPublicId()->toString()],
            'transcode_jobs' => [$job->getId()->toString(), $job->getPublicId()->toString()],
            'party_sessions' => [$session->getId()->toString(), $session->getPublicId()->toString()],
        ];
        $connection->expects($this->exactly(8))->method('fetchOne')->willReturnCallback(static function (string $sql, array $parameters) use ($rows): string {
            foreach ($rows as $table => [$internalId, $publicId]) {
                if (str_contains($sql, ' FROM ' . $table . ' WHERE ')) {
                    if (str_starts_with($sql, 'SELECT public_id')) {
                        self::assertSame(['id' => $internalId], $parameters);

                        return $publicId;
                    }
                    self::assertSame(['publicId' => $publicId], $parameters);

                    return $internalId;
                }
            }
            throw new \LogicException('Unexpected identity query.');
        });
        $identities = new PartyPublicIdentity($connection, $this->createStub(\App\Party\Application\Port\PartyMediaAccessPortInterface::class));

        self::assertEquals($video->getId(), $identities->videoId($video->getPublicId()));
        self::assertEquals($job->getId(), $identities->transcodeJobId($job->getPublicId()));
        self::assertEquals($user->getId(), $identities->userId($user->getPublicId()));
        $output = PartySessionResource::from($identities->session($session));
        $output['members'] = [PartyMemberResource::from($identities->member($member))];

        self::assertSame($session->getPublicId()->toString(), $output['publicId']);
        self::assertSame($user->getPublicId()->toString(), $output['hostUserId']);
        self::assertSame($video->getPublicId()->toString(), $output['videoId']);
        self::assertSame($job->getPublicId()->toString(), $output['transcodeJobId']);
        self::assertSame($member->getPublicId()->toString(), $output['members'][0]['publicId']);
        self::assertSame($session->getPublicId()->toString(), $output['members'][0]['sessionId']);
        self::assertSame($user->getPublicId()->toString(), $output['members'][0]['userId']);
        $json = json_encode($output, JSON_THROW_ON_ERROR);
        foreach ([$user->getId(), $video->getId(), $job->getId(), $session->getId(), $member->getId()] as $id) {
            self::assertStringNotContainsString($id->toString(), $json);
        }
    }

    public function testDeletedOptionalTranscodeJobProjectsNull(): void
    {
        $user = User::register(Email::fromString('host@baander.app'), 'hash', 'Host');
        $video = Video::create('/media/party.mp4', 'video-hash');
        $session = SyncedPartySession::create($user->getId(), $video->getId(), new \App\Shared\Domain\Model\Uuid());
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects($this->exactly(3))->method('fetchOne')->willReturnOnConsecutiveCalls($user->getPublicId()->toString(), $video->getPublicId()->toString(), false);
        $identities = new PartyPublicIdentity($connection, $this->createStub(\App\Party\Application\Port\PartyMediaAccessPortInterface::class));

        self::assertNull(PartySessionResource::from($identities->session($session))['transcodeJobId']);
    }
    public function testPublicVideoResolutionChecksPlaybackScopeBeforeAnyJobLookup(): void
    {
        $video = Video::create('/media/party.mp4', 'video-hash');
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with('SELECT id FROM videos WHERE public_id = :publicId', ['publicId' => $video->getPublicId()->toString()])
            ->willReturn($video->getId()->toString());
        $access = $this->createMock(\App\Party\Application\Port\PartyMediaAccessPortInterface::class);
        $access->expects($this->once())->method('assertHostCanPlay')->with($video->getId(), null)
            ->willThrowException(\App\Party\Application\Exception\PartyMediaNotFoundException::video());
        $this->expectException(\App\Party\Application\Exception\PartyMediaNotFoundException::class);

        (new PartyPublicIdentity($connection, $access))->videoId($video->getPublicId());
    }

}
