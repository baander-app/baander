<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\Tv;

use App\Catalog\Application\Command\Tv\RegisterTvEpisodeCommand;
use App\Catalog\Application\Command\Tv\RegisterTvSeriesCommand;
use App\Catalog\Application\CommandHandler\Tv\RegisterTvEpisodeHandler;
use App\Catalog\Application\CommandHandler\Tv\RegisterTvSeriesHandler;
use App\Catalog\Application\DTO\TvTitleRecord;
use App\Catalog\Application\Port\PersonCreditPortInterface;
use App\Catalog\Application\Port\TvCatalogPortInterface;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Metadata\Application\DTO\TvTitleMetadata;
use App\Metadata\Application\Port\PersonProfileSyncRequestInterface;
use App\Metadata\Application\Port\TvMetadataPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use PHPUnit\Framework\TestCase;

final class TvRegistrationHandlersTest extends TestCase
{
    public function testWrongLibraryTypeCannotFetchOrWriteTVMetadata(): void
    {
        $library = Library::create('Movies', new LibrarySlug('movie-tv-check'), new LibraryPath('/media/movies'), LibraryType::Movie, FilesystemType::Local);
        $libraries = $this->createStub(LibraryPortInterface::class);
        $libraries->method('findByUuid')->willReturn($library);
        $catalog = $this->createMock(TvCatalogPortInterface::class);
        $catalog->expects(self::never())->method('registerSeries');
        $metadata = $this->createMock(TvMetadataPortInterface::class);
        $metadata->expects(self::never())->method('series');
        $handler = new RegisterTvSeriesHandler(
            $libraries,
            $catalog,
            $metadata,
            $this->createStub(PersonCreditPortInterface::class),
            $this->createStub(TransactionPortInterface::class),
        );
        $this->expectException(InvalidInputException::class);
        $handler(new RegisterTvSeriesCommand($library->getId(), 12));
    }

    public function testSeriesWritesAndCreditsShareOneTransactionAfterTheLookup(): void
    {
        $library = Library::create('TV', new LibrarySlug('tv-handler'), new LibraryPath('/media/tv'), LibraryType::TvShow, FilesystemType::Local);
        $libraries = $this->createStub(LibraryPortInterface::class);
        $libraries->method('findByUuid')->willReturn($library);
        $title = new TvTitleRecord(Uuid::v7(), new PublicId(), $library->getId(), 12, 'A series');
        $data = new TvTitleMetadata(12, 'A series', null, null, [], null);
        $trace = new \stdClass();
        $trace->steps = ['start'];
        $metadata = $this->createMock(TvMetadataPortInterface::class);
        $metadata->expects(self::once())->method('series')->with(12)
            ->willReturnCallback(static function () use ($trace, $data): TvTitleMetadata {
                $trace->steps[] = 'lookup';

                return $data;
            });
        $transaction = $this->createMock(TransactionPortInterface::class);
        $transaction->expects(self::once())->method('run')
            ->willReturnCallback(static function (callable $operation) use ($trace): mixed {
                $trace->steps[] = 'transaction';

                $result = $operation();
                $trace->steps[] = 'committed';

                return $result;
            });
        $catalog = $this->createMock(TvCatalogPortInterface::class);
        $catalog->expects(self::once())->method('registerSeries')->with($library->getId(), $data)
            ->willReturnCallback(static function () use ($trace, $title): TvTitleRecord {
                self::assertSame(['start', 'lookup', 'transaction'], $trace->steps);

                return $title;
            });
        $credits = $this->createMock(PersonCreditPortInterface::class);
        $credits->expects(self::once())->method('syncTvCredits')->with('series', $title->id, [], null, true)
            ->willReturnCallback(static function () use ($trace): bool {
                self::assertSame(['start', 'lookup', 'transaction'], $trace->steps);

                return true;
            });
        $profiles = $this->createMock(PersonProfileSyncRequestInterface::class);
        $profiles->expects(self::once())->method('request')->willReturnCallback(static function () use ($trace): void {
            self::assertSame(['start', 'lookup', 'transaction', 'committed'], $trace->steps);
        });
        $handler = new RegisterTvSeriesHandler($libraries, $catalog, $metadata, $credits, $transaction, $profiles);
        self::assertSame($title, $handler(new RegisterTvSeriesCommand($library->getId(), 12, true)));
    }

    public function testEpisodeQueuesProfilesAfterItsAcceptedCreditsCommit(): void
    {
        $series = new TvTitleRecord(Uuid::v7(), new PublicId(), Uuid::v7(), 12, 'Series');
        $episode = new TvTitleRecord(Uuid::v7(), new PublicId(), $series->libraryId, 12, 'Episode', 0, 1);
        $catalog = $this->createStub(TvCatalogPortInterface::class);
        $catalog->method('findSeries')->willReturn($series);
        $catalog->method('registerEpisode')->willReturn($episode);
        $metadata = $this->createStub(TvMetadataPortInterface::class);
        $metadata->method('episode')->willReturn(new TvTitleMetadata(42, 'Episode', null, null, [], []));
        $credits = $this->createStub(PersonCreditPortInterface::class);
        $credits->method('syncTvCredits')->willReturn(true);
        $trace = new \stdClass();
        $trace->steps = ['start'];
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static function (callable $operation) use ($trace): mixed {
            $result = $operation();
            $trace->steps[] = 'committed';

            return $result;
        });
        $profiles = $this->createMock(PersonProfileSyncRequestInterface::class);
        $profiles->expects(self::once())->method('request')->willReturnCallback(static function () use ($trace): void {
            self::assertSame(['start', 'committed'], $trace->steps);
        });
        $handler = new RegisterTvEpisodeHandler($catalog, $metadata, $credits, $transaction, $profiles);

        self::assertSame($episode, $handler(new RegisterTvEpisodeCommand($series->publicId, 0, 1)));
    }

    public function testUnchangedEpisodeCreditsDoNotQueueProfileWork(): void
    {
        $series = new TvTitleRecord(Uuid::v7(), new PublicId(), Uuid::v7(), 12, 'Series');
        $episode = new TvTitleRecord(Uuid::v7(), new PublicId(), $series->libraryId, 12, 'Episode', 0, 1);
        $catalog = $this->createStub(TvCatalogPortInterface::class);
        $catalog->method('findSeries')->willReturn($series);
        $catalog->method('registerEpisode')->willReturn($episode);
        $metadata = $this->createStub(TvMetadataPortInterface::class);
        $metadata->method('episode')->willReturn(new TvTitleMetadata(42, 'Episode', null, null, null, null));
        $credits = $this->createStub(PersonCreditPortInterface::class);
        $credits->method('syncTvCredits')->willReturn(false);
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $profiles = $this->createMock(PersonProfileSyncRequestInterface::class);
        $profiles->expects(self::never())->method('request');
        $handler = new RegisterTvEpisodeHandler($catalog, $metadata, $credits, $transaction, $profiles);

        self::assertSame($episode, $handler(new RegisterTvEpisodeCommand($series->publicId, 0, 1)));
    }

    public function testInvalidEpisodeNumberCannotReadOrWriteSeries(): void
    {
        $catalog = $this->createMock(TvCatalogPortInterface::class);
        $catalog->expects(self::never())->method('findSeries');
        $metadata = $this->createMock(TvMetadataPortInterface::class);
        $metadata->expects(self::never())->method('episode');
        $handler = new RegisterTvEpisodeHandler(
            $catalog,
            $metadata,
            $this->createStub(PersonCreditPortInterface::class),
            $this->createStub(TransactionPortInterface::class),
        );
        $this->expectException(InvalidInputException::class);
        $handler(new RegisterTvEpisodeCommand(new PublicId(), 0, 0));
    }
}
