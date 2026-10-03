<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Domain\Model\TrackStreamMetadata;
use App\Media\Interface\Controller\StreamController;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Authentication and library authorization for the PublicId track endpoint. */
final class StreamControllerSecurityTest extends TestCase
{
    private string $mediaBasePath;
    private StreamPortInterface&Stub $streamService;
    private LibraryAccessPortInterface $libraryAccess;
    private StreamController $controller;

    protected function setUp(): void
    {
        $this->mediaBasePath = sys_get_temp_dir() . '/stream_base_' . uniqid('', true);
        mkdir($this->mediaBasePath, 0o755, true);

        $this->streamService = $this->createStub(StreamPortInterface::class);
        $this->libraryAccess = $this->createStub(LibraryAccessPortInterface::class);

        $this->controller = $this->createStreamControllerFixture();
    }

    private function createStreamControllerFixture(): StreamController
    {
        $fixture = new StreamController(
            streamService: $this->streamService,
            security: null,
            libraryAccess: $this->libraryAccess,
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $fixture->setTranslator($translator);
        return $fixture;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->mediaBasePath);
    }

    public function testStreamByIdRequiresAuthentication(): void
    {
        $trackId = new PublicId();
        $filePath = $this->mediaBasePath . '/track.mp3';
        file_put_contents($filePath, 'audio-data');

        $metadata = new TrackStreamMetadata(
            publicId: $trackId->toString(),
            filename: 'track.mp3',
            filePath: $filePath,
            mimeType: 'audio/mpeg',
            size: 10,
            codec: null,
            bitrate: null,
            sampleRate: null,
            channels: null,
            length: null,
        );

        $this->streamService->method('getTrackMetadata')->willReturn($metadata);
        $this->streamService->method('resolveTrackPath')->willReturn($filePath);

        $request = new Request(['id' => $trackId->toString()]);
        $response = $this->controller->streamById($request);

        $this->assertNotInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testStreamByIdRejectsMissingLibraryAccess(): void
    {
        $this->libraryAccess = $this->createMock(LibraryAccessPortInterface::class);
        $this->controller = $this->createStreamControllerFixture();

        $trackId = new PublicId();
        $libraryId = Uuid::fromString('550e8400-e29b-41d4-a716-446655440000');
        $userId = Uuid::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        $filePath = $this->mediaBasePath . '/track.mp3';
        file_put_contents($filePath, 'audio-data');

        $metadata = new TrackStreamMetadata(
            publicId: $trackId->toString(),
            filename: 'track.mp3',
            filePath: $filePath,
            mimeType: 'audio/mpeg',
            size: 10,
            codec: null,
            bitrate: null,
            sampleRate: null,
            channels: null,
            length: null,
        );

        $this->streamService->method('getLibraryIdForTrack')->willReturn($libraryId);
        $this->streamService->method('getTrackMetadata')->willReturn($metadata);
        $this->streamService->method('resolveTrackPath')->willReturn($filePath);

        $this->libraryAccess
            ->expects($this->once())
            ->method('hasAccess')
            ->with($userId, $libraryId)
            ->willReturn(false);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser(
            id: $userId->toString(),
            email: 'user@baander.app',
            password: 'password',
        ));

        $controller = new StreamController(
            streamService: $this->streamService,
            security: $security,
            libraryAccess: $this->libraryAccess,
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        $request = new Request(['id' => $trackId->toString()]);
        $response = $controller->streamById($request);

        $this->assertNotInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testStreamByIdAllowsAccessWhenUserHasLibraryAccess(): void
    {
        $this->libraryAccess = $this->createMock(LibraryAccessPortInterface::class);
        $this->controller = $this->createStreamControllerFixture();

        $trackId = new PublicId();
        $libraryId = Uuid::fromString('550e8400-e29b-41d4-a716-446655440000');
        $userId = Uuid::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        $filePath = $this->mediaBasePath . '/track.mp3';
        file_put_contents($filePath, 'audio-data');

        $metadata = new TrackStreamMetadata(
            publicId: $trackId->toString(),
            filename: 'track.mp3',
            filePath: $filePath,
            mimeType: 'audio/mpeg',
            size: 10,
            codec: null,
            bitrate: null,
            sampleRate: null,
            channels: null,
            length: null,
        );

        $this->streamService->method('getLibraryIdForTrack')->willReturn($libraryId);
        $this->streamService->method('getTrackMetadata')->willReturn($metadata);
        $this->streamService->method('resolveTrackPath')->willReturn($filePath);

        $this->libraryAccess
            ->expects($this->once())
            ->method('hasAccess')
            ->with($userId, $libraryId)
            ->willReturn(true);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser(
            id: $userId->toString(),
            email: 'user@baander.app',
            password: 'password',
        ));

        $controller = new StreamController(
            streamService: $this->streamService,
            security: $security,
            libraryAccess: $this->libraryAccess,
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        $request = new Request(['id' => $trackId->toString()]);
        $response = $controller->streamById($request);

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
