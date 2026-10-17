<?php

declare(strict_types=1);

namespace App\Party\Infrastructure;

use App\Party\Application\DTO\PartyMemberView;
use App\Party\Application\DTO\PartySessionView;
use App\Party\Application\Exception\PartyMediaNotFoundException;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartyMediaAccessPortInterface;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;

final readonly class PartyPublicIdentity implements PartyPublicIdentityPortInterface
{
    public function __construct(private Connection $connection, private PartyMediaAccessPortInterface $mediaAccess)
    {
    }

    public function session(SyncedPartySession $session): PartySessionView
    {
        $host = $this->userPublicId($session->getHostUserId());
        $video = $this->publicId('videos', $session->getVideoId()) ?? throw PartyMediaNotFoundException::video();
        $job = $session->getTranscodeJobId() === null ? null : $this->publicId('transcode_jobs', $session->getTranscodeJobId());

        return new PartySessionView($session, $host, $video, $job);
    }

    public function member(PartyMember $member): PartyMemberView
    {
        $user = $this->userPublicId($member->getUserId());
        $session = $this->publicId('party_sessions', $member->getSessionId()) ?? throw new NotFoundException('Party session not found.');

        return new PartyMemberView($member, $user, $session);
    }

    public function videoId(PublicId $publicId): Uuid
    {
        $id = $this->internalId('videos', $publicId) ?? throw PartyMediaNotFoundException::video();
        $this->mediaAccess->assertHostCanPlay($id, null);

        return $id;
    }

    public function transcodeJobId(PublicId $publicId): Uuid
    {
        return $this->internalId('transcode_jobs', $publicId) ?? throw PartyMediaNotFoundException::transcodeJob();
    }

    public function userId(PublicId $publicId): Uuid
    {
        return $this->internalId('users', $publicId) ?? throw new NotFoundException('User not found.');
    }

    public function userPublicId(Uuid $id): PublicId
    {
        return $this->publicId('users', $id) ?? throw new NotFoundException('Party user not found.');
    }

    /** @param 'users'|'videos'|'transcode_jobs'|'party_sessions' $table */
    private function publicId(string $table, Uuid $id): ?PublicId
    {
        $value = $this->connection->fetchOne('SELECT public_id FROM ' . $table . ' WHERE id = :id', ['id' => $id->toString()]);

        return is_string($value) ? PublicId::fromString($value) : null;
    }

    /** @param 'users'|'videos'|'transcode_jobs' $table */
    private function internalId(string $table, PublicId $publicId): ?Uuid
    {
        $value = $this->connection->fetchOne('SELECT id FROM ' . $table . ' WHERE public_id = :publicId', ['publicId' => $publicId->toString()]);

        return is_string($value) ? Uuid::fromString($value) : null;
    }
}
