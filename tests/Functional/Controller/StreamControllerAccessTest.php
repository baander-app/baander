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

    private function uri(): string
    {
        return '/api/stream/track?id=' . $this->trackId->toString();
    }
}
