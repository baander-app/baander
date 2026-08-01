<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Filesystem\Mime\MimeDetector;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Domain\Model\TrackStreamMetadata;
use App\Media\Interface\Controller\StreamController;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Security-focused tests for StreamController.
 *
 * The streaming endpoints currently perform no authorization checks and the
 * path-based endpoint uses a realpath() guard that can be bypassed for
 * non-existent traversal paths. These tests assert the expected secure
 * behaviour and fail against the current production code.
 */
final class StreamControllerSecurityTest extends TestCase
{
    private string $mediaBasePath;
    private StreamPortInterface&MockObject $streamService;
    private MimeDetector $mimeDetector;
    private LibraryAccessPortInterface&MockObject $libraryAccess;
    private StreamController $controller;

    protected function setUp(): void
    {
        $this->mediaBasePath = sys_get_temp_dir() . '/stream_base_' . uniqid('', true);
        mkdir($this->mediaBasePath, 0o755, true);

        $this->streamService = $this->createMock(StreamPortInterface::class);
        $this->mimeDetector = new MimeDetector();
        $this->libraryAccess = $this->createMock(LibraryAccessPortInterface::class);

        $this->controller = new StreamController(
            mediaBasePath: $this->mediaBasePath,
            mimeDetector: $this->mimeDetector,
            streamService: $this->streamService,
            security: null,
            libraryAccess: $this->libraryAccess,
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $this->controller->setTranslator($translator);
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

        // The endpoint should reject unauthenticated requests before serving
        // media. Currently it returns the file successfully.
        $this->assertNotInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testStreamByIdRejectsMissingLibraryAccess(): void
    {
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

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser(
            id: $userId->toString(),
            email: 'user@example.com',
            password: 'password',
        ));

        $controller = new StreamController(
            mediaBasePath: $this->mediaBasePath,
            mimeDetector: $this->mimeDetector,
            streamService: $this->streamService,
            security: $security,
            libraryAccess: $this->libraryAccess,
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        $request = new Request(['id' => $trackId->toString()]);
        $response = $controller->streamById($request);

        $this->assertNotInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testStreamByIdAllowsAccessWhenUserHasLibraryAccess(): void
    {
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

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser(
            id: $userId->toString(),
            email: 'user@example.com',
            password: 'password',
        ));

        $controller = new StreamController(
            mediaBasePath: $this->mediaBasePath,
            mimeDetector: $this->mimeDetector,
            streamService: $this->streamService,
            security: $security,
            libraryAccess: $this->libraryAccess,
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        $request = new Request(['id' => $trackId->toString()]);
        $response = $controller->streamById($request);

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testStreamByPathRequiresAuthentication(): void
    {
        file_put_contents($this->mediaBasePath . '/song.mp3', "\xFF\xFB" . str_repeat("\x00", 100));

        $request = new Request(['path' => 'song.mp3']);
        $response = $this->controller->stream($request);

        // The endpoint should reject unauthenticated requests before serving
        // media. Currently it returns the file successfully.
        $this->assertNotInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testStreamByPathRejectsTraversalToNonExistentOutsidePath(): void
    {
        $request = new Request(['path' => '../../../etc/passwd-does-not-exist']);
        $response = $this->controller->stream($request);

        // The realpath() guard returns false for non-existent paths and the
        // fallback to '' makes str_starts_with() pass. The request then fails
        // on file_exists() with a 404 instead of being rejected as a traversal
        // attempt. A secure implementation should reject the traversal up front.
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
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
