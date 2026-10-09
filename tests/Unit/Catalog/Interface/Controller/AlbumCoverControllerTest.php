<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Controller;

use App\Catalog\Interface\Controller\AlbumCoverController;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Unit\Catalog\Application\CommandHandler\Cover\CoverTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * The controller hands the uploaded file's temporary path to the cover use case, which runs
 * here against image storage in a temporary directory.
 */
final class AlbumCoverControllerTest extends CoverTestCase
{
    public function testUploadingAJpegOverAJpegCoverKeepsTheNewImageOnDisk(): void
    {
        $album = $this->album();
        // The old upload code stored every JPEG cover of an album at this one path.
        $old = $this->existingCover($album, 'images/album/' . $album->getId()->toString() . '.jpg');

        $response = $this->controller()->upload($album->getPublicId()->toString(), $this->upload($this->jpeg(8, 6)));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame(['publicId', 'url', 'size', 'width', 'height'], array_keys($data));
        self::assertSame('/api/images/' . $data['publicId'] . '/file', $data['url']);
        self::assertSame([8, 6], [$data['width'], $data['height']]);

        $new = $this->images->findByPublicId(PublicId::fromString($data['publicId']));
        self::assertNotNull($new);
        self::assertNotSame($old->getPath(), $new->getPath());
        self::assertFileExists($this->storage->resolve($new->getPath()));
        self::assertSame([$new->getPath()], $this->storedFiles());
        self::assertSame($new->getId()->toString(), $this->storedCovers[$album->getPublicId()->toString()]);
    }

    public function testAnUploadWithoutAFileIsInvalidInput(): void
    {
        $album = $this->album();

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('No file uploaded.');

        $this->controller()->upload($album->getPublicId()->toString(), new Request());
    }

    public function testAMalformedPublicIdIsInvalidInput(): void
    {
        $failure = $this->failureOf(fn () => $this->controller()->upload('invalid public id!', $this->upload($this->jpeg(2, 2))));

        self::assertInstanceOf(InvalidInputException::class, $failure);
        self::assertSame([], $this->storedFiles());

        self::assertInstanceOf(InvalidInputException::class, $this->failureOf(fn () => $this->controller()->delete('invalid-public-id!')));
    }

    public function testDeleteRemovesTheCoverAndAnswersNoContent(): void
    {
        $album = $this->album();
        $image = $this->existingCover($album, 'images/album/' . $album->getId()->toString() . '/cover.jpg');

        $response = $this->controller()->delete($album->getPublicId()->toString());

        self::assertSame(204, $response->getStatusCode());
        self::assertNull($this->storedCovers[$album->getPublicId()->toString()]);
        self::assertNull($this->images->findByUuid($image->getId()));
        self::assertSame([], $this->storedFiles());
    }

    public function testDeletingAMissingCoverOrAlbumIsNotFound(): void
    {
        $album = $this->album();

        self::assertInstanceOf(NotFoundException::class, $this->failureOf(fn () => $this->controller()->delete($album->getPublicId()->toString())));
        self::assertInstanceOf(NotFoundException::class, $this->failureOf(fn () => $this->controller()->delete((new PublicId())->toString())));
    }

    private function controller(): AlbumCoverController
    {
        return new AlbumCoverController($this->bus());
    }

    private function upload(string $path): Request
    {
        return new Request(files: ['cover' => new UploadedFile($path, 'cover.jpg', 'image/jpeg', UPLOAD_ERR_OK, true)]);
    }

    /** The use case's own exception, as ExceptionSubscriber unwraps it. */
    private function failureOf(callable $call): \Throwable
    {
        try {
            $call();
        } catch (HandlerFailedException $exception) {
            return HandlerFailure::cause($exception);
        }

        self::fail('The call must fail.');
    }
}
