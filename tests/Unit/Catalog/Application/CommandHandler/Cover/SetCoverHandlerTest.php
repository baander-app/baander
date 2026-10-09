<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\Cover;

use App\Catalog\Application\Command\Cover\CoverOwner;
use App\Catalog\Application\Command\Cover\SetCoverCommand;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;

final class SetCoverHandlerTest extends CoverTestCase
{
    public function testReplacingAJpegCoverWithAJpegKeepsTheNewFileAndDeletesTheOldCover(): void
    {
        $album = $this->album();
        // The path the old upload code gave every JPEG cover of this album.
        $old = $this->existingCover($album, 'images/album/' . $album->getId()->toString() . '.jpg');

        $cover = ($this->setCoverHandler())(new SetCoverCommand(CoverOwner::Album, $album->getPublicId()->toString(), $this->jpeg(8, 6)));

        $new = $this->images->findByPublicId(PublicId::fromString($cover->publicId));
        self::assertNotNull($new);
        self::assertFileExists($this->storage->resolve($new->getPath()));
        self::assertSame((int) filesize($this->storage->resolve($new->getPath())), $cover->size);
        self::assertSame([8, 6], [$cover->width, $cover->height]);
        self::assertSame($new->getId()->toString(), $this->storedCovers[$album->getPublicId()->toString()]);
        self::assertSame([$new->getPath()], $this->storedFiles(), 'the old file and its derived WebP are deleted');
        self::assertNull($this->images->findByUuid($old->getId()));
    }

    public function testEachCoverOfAnOwnerGetsItsOwnPath(): void
    {
        $album = $this->album();
        $handler = $this->setCoverHandler();
        $publicId = $album->getPublicId()->toString();

        $first = $handler(new SetCoverCommand(CoverOwner::Album, $publicId, $this->jpeg(2, 2)));
        $firstPath = $this->images->findByPublicId(PublicId::fromString($first->publicId))?->getPath();
        $second = $handler(new SetCoverCommand(CoverOwner::Album, $publicId, $this->jpeg(3, 3)));
        $secondPath = $this->images->findByPublicId(PublicId::fromString($second->publicId))?->getPath();

        self::assertNotSame($firstPath, $secondPath);
        self::assertStringStartsWith('images/album/' . $album->getId()->toString() . '/', (string) $secondPath);
        self::assertSame([$secondPath], $this->storedFiles());
    }

    public function testAFailedSaveRemovesTheNewFileAndKeepsTheOldCover(): void
    {
        $album = $this->album();
        $old = $this->existingCover($album, 'images/album/' . $album->getId()->toString() . '.jpg');
        $filesBefore = $this->storedFiles();
        $this->ownerSaveFailure = new \RuntimeException('database unavailable');

        try {
            ($this->setCoverHandler())(new SetCoverCommand(CoverOwner::Album, $album->getPublicId()->toString(), $this->jpeg(8, 6)));
            self::fail('The failed save must surface.');
        } catch (\RuntimeException $exception) {
            self::assertSame('database unavailable', $exception->getMessage());
        }

        self::assertSame($filesBefore, $this->storedFiles());
        self::assertSame([$old->getId()->toString()], array_keys($this->images->records));
        self::assertSame($old->getId()->toString(), $this->storedCovers[$album->getPublicId()->toString()]);
    }

    public function testAnArtistCoverIsStoredForTheArtist(): void
    {
        $artist = $this->artist();

        $cover = ($this->setCoverHandler())(new SetCoverCommand(CoverOwner::Artist, $artist->getPublicId()->toString(), $this->jpeg(5, 5)));

        $image = $this->images->findByPublicId(PublicId::fromString($cover->publicId));
        self::assertNotNull($image);
        self::assertSame('artist', $image->getImageableType());
        self::assertSame($artist->getId()->toString(), $image->getArtistId()?->toString());
        self::assertStringStartsWith('images/artist/' . $artist->getId()->toString() . '/', $image->getPath());
        self::assertSame($image->getId()->toString(), $this->storedCovers[$artist->getPublicId()->toString()]);
    }

    public function testAFailedCleanupOfTheOldCoverStillReportsTheNewCover(): void
    {
        $album = $this->album();
        $this->existingCover($album, 'images/album/old.jpg');
        $this->storage->deleteFailure = new \RuntimeException('read-only file system');

        $cover = ($this->setCoverHandler())(new SetCoverCommand(CoverOwner::Album, $album->getPublicId()->toString(), $this->jpeg(8, 6)));

        $new = $this->images->findByPublicId(PublicId::fromString($cover->publicId));
        self::assertSame($new?->getId()->toString(), $this->storedCovers[$album->getPublicId()->toString()]);
        self::assertSame(['Failed to delete a cover image that its owner no longer uses'], $this->loggedMessages());
    }

    /**
     * @return iterable<string, array{\Closure(self): string, string}>
     */
    public static function rejectedFiles(): iterable
    {
        yield 'a missing path' => [static fn (self $test): string => $test->root . '/missing.jpg', 'The cover file does not exist or cannot be read.'];
        yield 'a directory' => [static fn (self $test): string => $test->root, 'The cover file does not exist or cannot be read.'];
        yield 'a file over 10 MB' => [static function (self $test): string {
            $path = $test->jpeg(2, 2);
            $handle = fopen($path, 'r+');
            self::assertNotFalse($handle);
            ftruncate($handle, 10 * 1024 * 1024 + 1);
            fclose($handle);

            return $path;
        }, 'File size exceeds maximum of 10 MB.'];
        yield 'a non-image' => [static function (self $test): string {
            $path = $test->root . '/notes.txt';
            file_put_contents($path, 'not an image');

            return $path;
        }, 'Unsupported image type "text/plain". Allowed: jpeg, png, webp.'];
    }

    /**
     * @param \Closure(self): string $file
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedFiles')]
    public function testAFileThatCannotBeACoverIsInvalidInputAndChangesNothing(\Closure $file, string $message): void
    {
        $album = $this->album();

        try {
            ($this->setCoverHandler())(new SetCoverCommand(CoverOwner::Album, $album->getPublicId()->toString(), $file($this)));
            self::fail('The file must be rejected.');
        } catch (InvalidInputException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame([], $this->storedFiles());
        self::assertSame([], $this->images->records);
        self::assertNull($album->getCoverImageId());
    }

    public function testAMalformedPublicIdIsInvalidInputAndAnUnknownOneIsNotFound(): void
    {
        $handler = $this->setCoverHandler();

        try {
            $handler(new SetCoverCommand(CoverOwner::Album, 'not a public id!', $this->jpeg(2, 2)));
            self::fail('A malformed public ID must be rejected.');
        } catch (InvalidInputException $exception) {
            self::assertSame('Invalid public ID format.', $exception->getMessage());
        }

        $unknown = (new PublicId())->toString();
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(sprintf('Artist "%s" not found.', $unknown));
        $handler(new SetCoverCommand(CoverOwner::Artist, $unknown, $this->jpeg(2, 2)));
    }
}
