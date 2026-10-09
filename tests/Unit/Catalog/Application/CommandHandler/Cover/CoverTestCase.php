<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\Cover;

use App\Catalog\Application\Command\Cover\RemoveCoverCommand;
use App\Catalog\Application\Command\Cover\SetCoverCommand;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Application\Service\CoverOwners;
use App\Catalog\Application\CommandHandler\Cover\RemoveCoverHandler;
use App\Catalog\Application\CommandHandler\Cover\SetCoverHandler;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Filesystem\Application\Port\MimeDetectorPortInterface;
use App\Media\Domain\Model\Image;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * Runs the cover use cases against image storage in a temporary directory and in-memory
 * records, so a test sees which files and records a set or remove leaves behind.
 *
 * Records written inside the transaction are rolled back when it fails; an album or artist
 * save stores the cover ID it carries at that moment.
 */
abstract class CoverTestCase extends TestCase
{
    protected string $root;
    protected DirectoryStorage $storage;
    protected InMemoryImages $images;
    /** @var array<string, Album|Artist> by public ID */
    protected array $owners = [];
    /** @var array<string, string|null> the stored cover image ID, by owner public ID */
    protected array $storedCovers = [];
    protected ?\Throwable $ownerSaveFailure = null;
    /** @var AbstractLogger&object{messages: list<string>} */
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-cover-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->storage = new DirectoryStorage($this->root);
        $this->images = new InMemoryImages();
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    protected function setCoverHandler(): SetCoverHandler
    {
        return new SetCoverHandler(
            $this->coverOwners(),
            $this->images,
            $this->storage,
            $this->mimeDetector(),
            $this->transaction(),
            $this->discarder(),
            $this->logger,
        );
    }

    protected function removeCoverHandler(): RemoveCoverHandler
    {
        return new RemoveCoverHandler($this->coverOwners(), $this->discarder());
    }

    protected function bus(): MessageBus
    {
        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            SetCoverCommand::class => [$this->setCoverHandler()],
            RemoveCoverCommand::class => [$this->removeCoverHandler()],
        ]))]);
    }

    protected function album(): Album
    {
        $album = Album::create(libraryId: Uuid::v4(), title: 'Cover fixture album', type: 'album');
        $this->owners[$album->getPublicId()->toString()] = $album;

        return $album;
    }

    protected function artist(): Artist
    {
        $artist = Artist::create('Cover fixture artist');
        $this->owners[$artist->getPublicId()->toString()] = $artist;

        return $artist;
    }

    /** Gives the album a stored JPEG cover at the given path, with a derived WebP next to it. */
    protected function existingCover(Album $album, string $relativePath): Image
    {
        $this->storage->store($this->jpeg(4, 4), $relativePath);
        file_put_contents($this->derivedWebp($relativePath), 'derived');
        $image = Image::create(
            path: $relativePath,
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: (int) filesize($this->storage->resolve($relativePath)),
            width: 4,
            height: 4,
            imageableType: 'album',
            albumId: $album->getId(),
        );
        $this->images->save($image);
        $album->setCoverImage($image->getId());
        $this->storedCovers[$album->getPublicId()->toString()] = $image->getId()->toString();

        return $image;
    }

    protected function derivedWebp(string $relativePath): string
    {
        return $this->storage->resolve(dirname($relativePath) . '/' . pathinfo($relativePath, PATHINFO_FILENAME) . '.webp');
    }

    /** A real JPEG of the given size in the temporary directory, outside image storage. */
    protected function jpeg(int $width, int $height): string
    {
        $path = $this->root . '/upload-' . bin2hex(random_bytes(4)) . '.jpg';
        $canvas = imagecreatetruecolor($width, $height);
        self::assertNotFalse($canvas);
        imagejpeg($canvas, $path);

        return $path;
    }

    /** @return list<string> every file under image storage, relative to it */
    protected function storedFiles(): array
    {
        $images = $this->root . '/images';
        if (!is_dir($images)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($images, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = substr((string) $file, strlen($this->root) + 1);
        }
        sort($files);

        return $files;
    }

    private function coverOwners(): CoverOwners
    {
        $find = fn (PublicId $id): Album|Artist|null => $this->owners[$id->toString()] ?? null;
        $save = function (Album|Artist $owner): void {
            if ($this->ownerSaveFailure !== null) {
                throw $this->ownerSaveFailure;
            }
            $this->storedCovers[$owner->getPublicId()->toString()] = $owner->getCoverImageId()?->toString();
        };

        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByPublicId')->willReturnCallback(fn (PublicId $id): ?Album => ($owner = $find($id)) instanceof Album ? $owner : null);
        $albums->method('save')->willReturnCallback($save);
        $artists = $this->createStub(ArtistPortInterface::class);
        $artists->method('findByPublicId')->willReturnCallback(fn (PublicId $id): ?Artist => ($owner = $find($id)) instanceof Artist ? $owner : null);
        $artists->method('save')->willReturnCallback($save);

        return new CoverOwners($albums, $artists);
    }

    private function discarder(): CoverImageDiscarder
    {
        return new CoverImageDiscarder($this->images, $this->storage, $this->logger);
    }

    private function mimeDetector(): MimeDetectorPortInterface
    {
        $detector = $this->createStub(MimeDetectorPortInterface::class);
        $detector->method('detect')->willReturnCallback(static function (string $path): string {
            $info = @getimagesize($path);

            return $info !== false ? $info['mime'] : 'text/plain';
        });

        return $detector;
    }

    private function transaction(): TransactionPortInterface
    {
        $images = $this->images;

        return new class ($images) implements TransactionPortInterface {
            public function __construct(private InMemoryImages $images)
            {
            }

            public function run(callable $operation): mixed
            {
                $snapshot = $this->images->records;
                try {
                    return $operation();
                } catch (\Throwable $error) {
                    $this->images->records = $snapshot;

                    throw $error;
                }
            }
        };
    }

    /** @return list<string> the messages logged at any level */
    protected function loggedMessages(): array
    {
        return $this->logger->messages;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
