<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\Party\PartyFixtures;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creating a party checks its video and transcode job against the playback authority: the job
 * must be the host's own. The party keeps the job only while the job's owner is a member.
 */
final class PartySessionCreationTest extends TestCase
{
    use PartyFixtures;

    private const URI = '/api/party/sessions/';

    private User $host;
    private LibraryEntity $hostLibrary;
    private VideoEntity $video;
    private PublicId $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostLibrary = $this->library();
        $this->host = $this->viewer($this->hostLibrary);
        $this->video = $this->video($this->hostLibrary);
        $this->job = $this->job($this->video, $this->host);
    }

    public function testCreatesAPartyForAPlayableVideoAndItsJob(): void
    {
        $data = $this->assertJsonResponse($this->create(['videoId' => $this->video->getPublicId()->toString(), 'transcodeJobId' => $this->job->toString()]), 201, 'data')['data'];

        self::assertSame($this->video->getPublicId()->toString(), $data['videoId']);
        self::assertSame($this->job->toString(), $data['transcodeJobId']);
        self::assertSame($this->host->getPublicId()->toString(), $data['hostUserId']);
        self::assertSame(1, $this->countParties($this->video));
        self::assertArrayNotHasKey('uuid', $data);
        self::assertStringNotContainsString($this->video->getId()->toString(), json_encode($data, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($this->host->getId()->toString(), json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testCreatesAPartyWithoutAJob(): void
    {
        $data = $this->assertJsonResponse($this->create(['videoId' => $this->video->getPublicId()->toString()]), 201, 'data')['data'];

        self::assertNull($data['transcodeJobId']);
    }

    public function testANonexistentVideoIsNotFound(): void
    {
        $this->assertJsonResponse($this->create(['videoId' => (new PublicId())->toString()]), 404);
    }

    public function testAVideoOutsideTheHostsLibrariesIsNotFound(): void
    {
        $hidden = $this->video($this->library());

        $response = $this->assertJsonResponse($this->create(['videoId' => $hidden->getPublicId()->toString()]), 404);
        $missing = $this->assertJsonResponse($this->create(['videoId' => (new PublicId())->toString()]), 404);

        // The response does not reveal that the inaccessible video exists.
        self::assertSame($missing, $response);
        self::assertSame(0, $this->countParties($hidden));
    }

    public function testANonexistentJobIsNotFound(): void
    {
        $this->assertJsonResponse($this->create(['videoId' => $this->video->getPublicId()->toString(), 'transcodeJobId' => (new PublicId())->toString()]), 404);
        self::assertSame(0, $this->countParties($this->video));
    }

    public function testAnotherUsersJobOfAVideoBothMayPlayIsNotFound(): void
    {
        $foreignJob = $this->job($this->video, $this->viewer($this->hostLibrary));

        $response = $this->assertJsonResponse($this->create(['videoId' => $this->video->getPublicId()->toString(), 'transcodeJobId' => $foreignJob->toString()]), 404);
        $missing = $this->assertJsonResponse($this->create(['videoId' => $this->video->getPublicId()->toString(), 'transcodeJobId' => (new PublicId())->toString()]), 404);

        // The response does not reveal that the other user's job exists.
        self::assertSame($missing, $response);
        self::assertSame(0, $this->countParties($this->video));
    }

    public function testAJobOfAVideoOutsideTheHostsLibrariesIsNotFound(): void
    {
        $hiddenJob = $this->job($this->video($this->library()), $this->host);

        $this->assertJsonResponse($this->create(['videoId' => $this->video->getPublicId()->toString(), 'transcodeJobId' => $hiddenJob->toString()]), 404);
        self::assertSame(0, $this->countParties($this->video));
    }

    public function testAJobOfAnotherVideoIsRejected(): void
    {
        $otherJob = $this->job($this->video($this->hostLibrary), $this->host);

        $error = $this->assertJsonResponse($this->create(['videoId' => $this->video->getPublicId()->toString(), 'transcodeJobId' => $otherJob->toString()]), 422);

        self::assertSame(['transcodeJobId' => ['The transcode job does not belong to the video.']], $error['error']['details']);
        self::assertSame(0, $this->countParties($this->video));
    }

    public function testAMalformedIdentifierIsAValidationError(): void
    {
        $this->assertJsonResponse($this->create(['videoId' => 'not-a-public-id']), 422);
        $this->assertJsonResponse($this->create(['videoId' => $this->video->getPublicId()->toString(), 'transcodeJobId' => 'not-a-public-id']), 422);
    }

    public function testKnownInternalVideoAndJobUuidsAreRejectedWithoutCreatingAParty(): void
    {
        $this->assertJsonResponse($this->create(['videoId' => $this->video->getId()->toString()]), 422);
        $jobId = $this->entityManager->getConnection()->fetchOne('SELECT id FROM transcode_jobs WHERE public_id = :publicId', ['publicId' => $this->job->toString()]);
        $this->assertJsonResponse($this->create(['videoId' => $this->video->getPublicId()->toString(), 'transcodeJobId' => $jobId]), 422);

        self::assertSame(0, $this->countParties($this->video));
    }

    public function testANewHostKeepsTheJobWhileItsOwnerStaysAndThePartyLosesItWhenTheOwnerLeaves(): void
    {
        [$party, $guest] = $this->partyWithAGuest();

        $this->assertJsonResponse($this->transfer($party, to: $guest), 200);
        self::assertSame($this->job->toString(), $this->session($party, $guest)['transcodeJobId']);

        $this->assertJsonResponse($this->authenticatedRequest('POST', self::URI . $party . '/leave', $this->host), 200);
        self::assertNull($this->session($party, $guest)['transcodeJobId']);
    }

    public function testAMemberWhoDoesNotOwnTheJobLeavesAndThePartyKeepsIt(): void
    {
        [$party, $guest] = $this->partyWithAGuest();

        $this->assertJsonResponse($this->authenticatedRequest('POST', self::URI . $party . '/leave', $guest), 200);

        self::assertSame($this->job->toString(), $this->session($party, $this->host)['transcodeJobId']);
    }

    public function testThePartyLosesTheJobWhenANewHostRemovesItsOwner(): void
    {
        [$party, $guest] = $this->partyWithAGuest();
        $this->assertJsonResponse($this->transfer($party, to: $guest), 200);

        $response = $this->authenticatedRequest('DELETE', $this->invitationUri($this->host, $party), $guest);

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertNull($this->session($party, $guest)['transcodeJobId']);
    }

    /** @param array<string, mixed> $body */
    private function create(array $body): Response
    {
        return $this->authenticatedRequest('POST', self::URI, $this->host, $body);
    }

    /**
     * A party the host creates with their own job, and a guest who joined it.
     *
     * @return array{string, User} the party's public ID and the guest
     */
    private function partyWithAGuest(): array
    {
        $party = $this->assertJsonResponse($this->create([
            'videoId' => $this->video->getPublicId()->toString(),
            'transcodeJobId' => $this->job->toString(),
        ]), 201, 'data')['data']['publicId'];
        $guest = $this->viewer($this->hostLibrary);
        $this->invite($this->host, $guest, $party);
        $this->join($guest, $party);

        return [$party, $guest];
    }

    private function transfer(string $party, User $to): Response
    {
        return $this->authenticatedRequest(
            'POST',
            self::URI . $party . '/members/transfer-host',
            $this->host,
            ['newHostUserId' => $to->getPublicId()->toString()],
        );
    }

    /** @return array<string, mixed> the party as one of its members reads it */
    private function session(string $party, User $member): array
    {
        return $this->assertJsonResponse($this->authenticatedRequest('GET', self::URI . $party, $member), 200, 'data')['data'];
    }

    /** A job of the video that the user owns. */
    private function job(VideoEntity $video, User $owner): PublicId
    {
        $publicId = new PublicId();
        $this->entityManager->getConnection()->insert('transcode_jobs', [
            'request_id' => Uuid::generate()->toString(),
            'id' => Uuid::generate()->toString(),
            'video_id' => $video->getId()->toString(),
            'owner_user_id' => $owner->getId()->toString(),
            'public_id' => $publicId->toString(),
            'quality_tier_name' => '1080p',
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);

        return $publicId;
    }

    private function countParties(VideoEntity $video): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) FROM party_sessions WHERE video_id = :video',
            ['video' => $video->getId()->toString()],
        );
    }
}
