<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Domain\Model\TrackStreamMetadata;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use App\Transcode\Application\Port\StreamAuthPortInterface;

final class StreamControllerAccessTest extends TestCase
{
    private string $directory;
    private PublicId $trackId;
    private Uuid $libraryId;
    private LibraryAccessPortInterface $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/baander-stream-access-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $file = $this->directory . '/track.mp3';
        self::assertSame(10, file_put_contents($file, 'audio-data'));

        $this->trackId = new PublicId();
        $library = new LibraryEntity(
            name: 'Stream access test',
            slug: 'stream-access-' . bin2hex(random_bytes(8)),
            path: $this->directory,
            type: 'music',
            filesystemType: 'local',
        );
        $this->entityManager->persist($library);
        $this->entityManager->flush();
        $this->libraryId = $library->getId();
        $this->access = self::getContainer()->get(LibraryAccessPortInterface::class);

        $stream = $this->createStub(StreamPortInterface::class);
        $stream->method('getLibraryIdForTrack')->willReturn($this->libraryId);
        $stream->method('resolveTrackPath')->willReturn($file);
        $stream->method('getTrackMetadata')->willReturn(new TrackStreamMetadata(
            publicId: $this->trackId->toString(),
            filename: 'track.mp3',
            filePath: $file,
            mimeType: 'audio/mpeg',
            size: 10,
            codec: null,
            bitrate: null,
            sampleRate: null,
            channels: null,
            length: null,
        ));
        self::getContainer()->set(StreamPortInterface::class, $stream);
    }

    protected function tearDown(): void
    {
        unlink($this->directory . '/track.mp3');
        rmdir($this->directory);

        parent::tearDown();
    }

    public function testAnonymousAndUnrelatedUsersCannotStreamTrack(): void
    {
        $this->assertJsonResponse($this->anonymousRequest('GET', $this->uri()), 401);

        $unrelated = $this->createTestUser();
        $this->assertJsonResponse($this->authenticatedRequest('GET', $this->uri(), $unrelated), 403);
    }

    public function testRevokedMembershipIsEnforcedOnTheNextStreamRequest(): void
    {
        $member = $this->createTestUser();
        $this->access->grant($member->getId(), $this->libraryId);

        self::assertSame(200, $this->authenticatedRequest('GET', $this->uri(), $member)->getStatusCode());

        $this->access->revoke($member->getId(), $this->libraryId);

        $this->assertJsonResponse($this->authenticatedRequest('GET', $this->uri(), $member), 403);
    }

    public function testAdministratorCanStreamWithoutLibraryMembership(): void
    {
        $admin = $this->createAdminUser();

        self::assertSame(200, $this->authenticatedRequest('GET', $this->uri(), $admin)->getStatusCode());
    }

    public function testSignedOriginalSupportsAnonymousRangesAndChecksRevokedMembership(): void
    {
        $member = $this->createTestUser();
        $this->access->grant($member->getId(), $this->libraryId);
        $url = $this->signedOriginal($member->getId());

        $this->client->request('GET', $url, server: ['HTTP_RANGE' => 'bytes=0-4']);
        $response = $this->client->getResponse();
        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 0-4/10', $response->headers->get('Content-Range'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));

        $this->access->revoke($member->getId(), $this->libraryId);
        $this->client->request('GET', $url, server: ['HTTP_RANGE' => 'bytes=5-9']);
        $this->assertJsonResponse($this->client->getResponse(), 403);
    }

    public function testSignedOriginalRejectsChangedOwnerAndExpiredSignature(): void
    {
        $member = $this->createTestUser();
        $this->access->grant($member->getId(), $this->libraryId);
        $url = $this->signedOriginal($member->getId());
        $changedOwner = str_replace($member->getId()->toString(), Uuid::generate()->toString(), $url);

        $this->assertJsonResponse($this->anonymousRequest('GET', $changedOwner), 403);
        $this->assertJsonResponse($this->anonymousRequest('GET', $this->signedOriginal($member->getId(), -60)), 403);
    }

    public function testPublicOriginalRoutesRequireTheirOwnAuthorization(): void
    {
        foreach (['/api/stream/original/track', '/api/stream/video'] as $path) {
            $this->assertJsonResponse($this->anonymousRequest('GET', $path . '?id=' . $this->trackId->toString()), 403);
        }
        $this->assertJsonResponse($this->anonymousRequest('GET', $this->uri()), 401);
    }

    private function signedOriginal(Uuid $owner, int $lifetime = 60): string
    {
        $path = '/api/stream/original/track?' . http_build_query([
            'id' => $this->trackId->toString(),
            'owner' => $owner->toString(),
        ]);
        return self::getContainer()->get(StreamAuthPortInterface::class)->signUrl($path, $lifetime)['url'];
    }

    private function uri(): string
    {
        return '/api/stream/track?id=' . $this->trackId->toString();
    }
}
