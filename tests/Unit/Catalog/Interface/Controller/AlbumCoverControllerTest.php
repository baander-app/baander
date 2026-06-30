<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Controller;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Interface\Controller\AlbumCoverController;
use App\Filesystem\Application\Port\MimeDetectorPortInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Media\Domain\Model\StoredFile;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AlbumCoverControllerTest extends TestCase
{
    private AlbumPortInterface&MockObject $albumService;
    private ImagePortInterface&MockObject $imagePort;
    private StoragePortInterface&MockObject $storage;
    private MimeDetectorPortInterface&MockObject $mimeDetector;
    private AlbumCoverController $controller;

    protected function setUp(): void
    {
        $this->albumService = $this->createMock(AlbumPortInterface::class);
        $this->imagePort = $this->createMock(ImagePortInterface::class);
        $this->storage = $this->createMock(StoragePortInterface::class);
        $this->mimeDetector = $this->createMock(MimeDetectorPortInterface::class);

        $this->controller = new AlbumCoverController(
            $this->albumService,
            $this->imagePort,
            $this->storage,
            $this->mimeDetector,
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $this->controller->setTranslator($translator);
    }

    protected function tearDown(): void
    {
        // Clean up any temp files created during tests
        $tmpFiles = glob(sys_get_temp_dir() . '/cover_test_*');
        if ($tmpFiles !== false) {
            foreach ($tmpFiles as $f) {
                @unlink($f);
            }
        }
    }

    private function createAlbum(?Uuid $coverImageId = null): Album
    {
        $album = Album::create(
            libraryId: Uuid::v4(),
            title: 'Test Album',
            type: 'album',
        );

        if ($coverImageId !== null) {
            $album->setCoverImage($coverImageId);
        }

        return $album;
    }

    /**
     * Creates an UploadedFile backed by a real temp file with the given content.
     */
    private function createUploadedFile(string $content, string $originalName = 'cover.jpg', string $mimeType = 'image/jpeg'): UploadedFile
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cover_test_');
        file_put_contents($tmpFile, $content);

        return new UploadedFile(
            path: $tmpFile,
            originalName: $originalName,
            mimeType: $mimeType,
            error: UPLOAD_ERR_OK,
        );
    }

    // --- Upload: happy path ---

    public function testUploadCreatesCoverImageForAlbum(): void
    {
        $album = $this->createAlbum();
        $publicId = $album->getPublicId()->toString();

        $this->albumService->method('findByPublicId')->willReturn($album);

        $file = $this->createUploadedFile("\xFF\xD8\xFF" . str_repeat("\x00", 100));

        $this->mimeDetector->method('detect')->willReturn('image/jpeg');

        $storedFile = new StoredFile(
            path: 'images/album/' . $album->getId()->toString() . '.jpg',
            mimeType: 'image/jpeg',
            size: 103,
        );
        $this->storage->method('storeFromBytes')->willReturn($storedFile);
        $this->imagePort->expects($this->once())->method('save');
        $this->albumService->expects($this->once())->method('save');

        $request = new Request(files: ['cover' => $file]);
        $response = $this->controller->upload($publicId, $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('publicId', $data['data']);
        $this->assertArrayHasKey('url', $data['data']);
        $this->assertArrayHasKey('size', $data['data']);
        $this->assertArrayHasKey('width', $data['data']);
        $this->assertArrayHasKey('height', $data['data']);

        // Album cover should be set
        $this->assertNotNull($album->getCoverImageId());
    }

    // --- Upload: replaces existing cover ---

    public function testUploadReplacesExistingCover(): void
    {
        $oldImage = Image::create(
            path: 'images/album/old.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 100,
            width: 100,
            height: 100,
            imageableType: 'album',
            albumId: Uuid::v4(),
        );

        $album = $this->createAlbum($oldImage->getId());
        $publicId = $album->getPublicId()->toString();

        $this->albumService->method('findByPublicId')->willReturn($album);

        // Old image should be found and deleted
        $this->imagePort->method('findByUuid')
            ->with($oldImage->getId())
            ->willReturn($oldImage);

        $this->storage->expects($this->once())->method('delete')->with($oldImage->getPath());
        $this->imagePort->expects($this->once())->method('delete')->with($oldImage);

        $file = $this->createUploadedFile("\xFF\xD8\xFF" . str_repeat("\x00", 100));

        $this->mimeDetector->method('detect')->willReturn('image/jpeg');

        $storedFile = new StoredFile(
            path: 'images/album/' . $album->getId()->toString() . '.jpg',
            mimeType: 'image/jpeg',
            size: 103,
        );
        $this->storage->method('storeFromBytes')->willReturn($storedFile);

        // New image should be saved
        $this->imagePort->expects($this->once())->method('save');
        $this->albumService->expects($this->once())->method('save');

        $request = new Request(files: ['cover' => $file]);
        $response = $this->controller->upload($publicId, $request);

        $this->assertSame(200, $response->getStatusCode());
    }

    // --- Upload: no file ---

    public function testUploadReturns422WhenNoFileProvided(): void
    {
        $album = $this->createAlbum();
        $publicId = $album->getPublicId()->toString();

        $this->albumService->method('findByPublicId')->willReturn($album);

        $request = new Request();
        $response = $this->controller->upload($publicId, $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());

        $this->imagePort->expects($this->never())->method('save');
    }

    // --- Upload: oversized file ---

    public function testUploadReturns422WhenFileIsTooLarge(): void
    {
        $album = $this->createAlbum();
        $publicId = $album->getPublicId()->toString();

        $this->albumService->method('findByPublicId')->willReturn($album);

        // Create an actual file > 10 MB (UploadedFile::getSize() reads from disk)
        $oversizedContent = str_repeat("\x00", 10 * 1024 * 1024 + 1);
        $file = $this->createUploadedFile($oversizedContent);

        $request = new Request(files: ['cover' => $file]);
        $response = $this->controller->upload($publicId, $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('10 MB', $data['error']['message']);

        $this->imagePort->expects($this->never())->method('save');
    }

    // --- Upload: wrong MIME type ---

    public function testUploadReturns422WhenMimeTypeIsUnsupported(): void
    {
        $album = $this->createAlbum();
        $publicId = $album->getPublicId()->toString();

        $this->albumService->method('findByPublicId')->willReturn($album);

        $file = $this->createUploadedFile('GIF89a' . str_repeat("\x00", 50), 'cover.gif', 'image/gif');

        $this->mimeDetector->method('detect')->willReturn('image/gif');

        $request = new Request(files: ['cover' => $file]);
        $response = $this->controller->upload($publicId, $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('image/gif', $data['error']['message']);

        $this->imagePort->expects($this->never())->method('save');
    }

    // --- Upload: non-existent album ---

    public function testUploadReturns404WhenAlbumDoesNotExist(): void
    {
        $publicId = (new PublicId())->toString();

        $this->albumService->method('findByPublicId')->willReturn(null);

        $file = $this->createUploadedFile("\xFF\xD8\xFF" . str_repeat("\x00", 100));

        $request = new Request(files: ['cover' => $file]);
        $response = $this->controller->upload($publicId, $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());

        $this->imagePort->expects($this->never())->method('save');
    }

    // --- Upload: invalid public ID ---

    public function testUploadReturns400WhenPublicIdIsInvalid(): void
    {
        // PublicId requires exactly 21 chars from [0-9a-zA-Z_-]
        // Use a string with a space to make it invalid
        $file = $this->createUploadedFile("\xFF\xD8\xFF" . str_repeat("\x00", 100));

        $request = new Request(files: ['cover' => $file]);
        $response = $this->controller->upload('invalid public id!', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
    }

    // --- Delete: happy path ---

    public function testDeleteRemovesCoverImageFromAlbum(): void
    {
        $image = Image::create(
            path: 'images/album/test.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 100,
            width: 200,
            height: 200,
            imageableType: 'album',
            albumId: Uuid::v4(),
        );

        $album = $this->createAlbum($image->getId());
        $publicId = $album->getPublicId()->toString();

        $this->albumService->method('findByPublicId')->willReturn($album);
        $this->imagePort->method('findByUuid')
            ->with($image->getId())
            ->willReturn($image);

        $this->storage->expects($this->once())->method('delete')->with($image->getPath());
        $this->imagePort->expects($this->once())->method('delete')->with($image);
        $this->albumService->expects($this->once())->method('save')->with($this->callback(
            static fn(Album $a): bool => $a->getCoverImageId() === null,
        ));

        $response = $this->controller->delete($publicId);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(204, $response->getStatusCode());
    }

    // --- Delete: no cover exists ---

    public function testDeleteReturns404WhenNoCoverExists(): void
    {
        $album = $this->createAlbum();
        $publicId = $album->getPublicId()->toString();

        $this->albumService->method('findByPublicId')->willReturn($album);

        $response = $this->controller->delete($publicId);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());

        $this->storage->expects($this->never())->method('delete');
        $this->imagePort->expects($this->never())->method('delete');
    }

    // --- Delete: non-existent album ---

    public function testDeleteReturns404WhenAlbumDoesNotExist(): void
    {
        $publicId = (new PublicId())->toString();

        $this->albumService->method('findByPublicId')->willReturn(null);

        $response = $this->controller->delete($publicId);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
    }

    // --- Delete: invalid public ID ---

    public function testDeleteReturns400WhenPublicIdIsInvalid(): void
    {
        // PublicId requires exactly 21 chars from [0-9a-zA-Z_-]
        $response = $this->controller->delete('invalid-public-id!');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
    }
}
