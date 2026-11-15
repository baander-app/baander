<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\DeleteArtistCommand;
use App\Catalog\Application\CommandHandler\Artist\DeleteArtistHandler;
use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Catalog\Application\Port\ArtistRemoval;
use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Repository\ArtistRepositoryInterface;
use App\Catalog\Infrastructure\ArtistService;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Application\Port\StoragePortInterface;
use App\Media\Domain\Model\Image;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Deleting an artist removes it with its credits, favorites and recommendations in one
 * transaction, through the artist merge port, and discards its cover only once that has committed.
 */
final class DeleteArtistHandlerTest extends TestCase
{
    /** @var list<string> what happened, in order */
    private array $log = [];
    private ?Image $cover = null;
    private ?\Throwable $removalFailure = null;

    public function testTheArtistIsRemovedInOneTransactionAndItsCoverDiscardedAfterTheCommit(): void
    {
        $artist = $this->artistWithCover();

        $result = self::command($artist) |> $this->handler($artist);

        self::assertSame(['begin', 'remove ' . $artist->getId()->toString(), 'commit', 'delete image', 'delete image file'], $this->log);
        self::assertSame(['artists' => 1, 'coverImages' => 1], $result->deleted);
    }

    public function testAFailedRemovalDiscardsNoImage(): void
    {
        $artist = $this->artistWithCover();
        $this->removalFailure = new \RuntimeException('The removal failed.');

        try {
            self::command($artist) |> $this->handler($artist);
            self::fail('The removal failure reaches the caller.');
        } catch (\RuntimeException $exception) {
            self::assertSame($this->removalFailure, $exception);
        }

        self::assertSame(['begin', 'remove ' . $artist->getId()->toString(), 'rollback'], $this->log);
    }

    public function testAnArtistWithoutACoverDiscardsNothing(): void
    {
        $artist = Artist::create('Headhunterz');

        $result = self::command($artist) |> $this->handler($artist);

        self::assertSame(['begin', 'remove ' . $artist->getId()->toString(), 'commit'], $this->log);
        self::assertSame(['artists' => 1, 'coverImages' => 0], $result->deleted);
    }

    public function testAnUnknownArtistIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        try {
            Artist::create('Headhunterz') |> self::command(...) |> $this->handler(null);
        } finally {
            self::assertSame([], $this->log);
        }
    }

    private static function command(Artist $artist): DeleteArtistCommand
    {
        $publicId = $artist->getPublicId()->toString();

        return new DeleteArtistCommand($publicId);
    }

    private function artistWithCover(): Artist
    {
        $artist = Artist::create('Headhunterz');
        $this->cover = Image::create(
            path: 'images/headhunterz.jpg',
            extension: 'jpg',
            mimeType: 'image/jpeg',
            size: 10,
            width: 1,
            height: 1,
            imageableType: 'artist',
            artistId: $artist->getId(),
        );
        $this->cover->getId() |> $artist->setCoverImage(...);

        return $artist;
    }

    private function handler(?Artist $artist): DeleteArtistHandler
    {
        $repository = $this->createStub(ArtistRepositoryInterface::class);
        $repository->method('findByPublicId')->willReturn($artist);

        $merges = $this->createStub(ArtistMergePortInterface::class);
        $merges->method('remove')->willReturnCallback(function (Uuid $artistId): ArtistRemoval {
            $this->log[] = 'remove ' . $artistId->toString();
            if ($this->removalFailure !== null) {
                throw $this->removalFailure;
            }

            return new ArtistRemoval($this->cover === null ? [] : [$this->cover->getId()]);
        });

        $service = new ArtistService(
            $repository,
            $this->transaction(),
            $merges,
            $this->discarder(),
        );

        return new DeleteArtistHandler($service);
    }

    private function transaction(): TransactionPortInterface
    {
        $record = function (string $event): void {
            $this->log[] = $event;
        };

        return new class($record) implements TransactionPortInterface {
            /** @param \Closure(string): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function run(callable $operation): mixed
            {
                ($this->record)('begin');
                try {
                    $result = $operation();
                } catch (\Throwable $exception) {
                    ($this->record)('rollback');
                    throw $exception;
                }
                ($this->record)('commit');

                return $result;
            }
        };
    }

    private function discarder(): CoverImageDiscarder
    {
        $images = $this->createStub(ImagePortInterface::class);
        $images->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?Image => $this->cover?->getId()->equals($id) === true ? $this->cover : null);
        $images->method('delete')->willReturnCallback(function (): void {
            $this->log[] = 'delete image';
        });
        $storage = $this->createStub(StoragePortInterface::class);
        $storage->method('delete')->willReturnCallback(function (): void {
            $this->log[] = 'delete image file';
        });

        return new CoverImageDiscarder($images, $storage, new NullLogger());
    }
}
