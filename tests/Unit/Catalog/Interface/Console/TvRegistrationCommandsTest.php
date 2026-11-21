<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Console;

use App\Catalog\Application\Command\Tv\RegisterTvEpisodeCommand;
use App\Catalog\Application\Command\Tv\RegisterTvSeriesCommand;
use App\Catalog\Application\DTO\TvTitleRecord;
use App\Catalog\Infrastructure\Messaging\CatalogMessagePayloadCodec;
use App\Catalog\Interface\Console\TvEpisodeRegisterCommand;
use App\Catalog\Interface\Console\TvSeriesRegisterCommand;
use App\Shared\Application\DTO\InlineJobRun;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class TvRegistrationCommandsTest extends TestCase
{
    public function testSeriesCommandUsesMonitoredApplicationMessageAndPrintsPublicIdentity(): void
    {
        $libraryId = Uuid::v7();
        $title = new TvTitleRecord(Uuid::v7(), new PublicId(), $libraryId, 45, 'A series');
        $jobs = $this->createMock(JobMonitorAdministrationInterface::class);
        $jobs->expects(self::once())->method('runInline')
            ->with(self::callback(static fn(object $message): bool => $message instanceof RegisterTvSeriesCommand
                && $message->libraryId->equals($libraryId)
                && $message->tmdbId === 45
                && $message->forceUpdate))
            ->willReturn(new InlineJobRun('tv-series-job', $title));
        $tester = new CommandTester(new TvSeriesRegisterCommand($jobs));
        self::assertSame(0, $tester->execute([
            'library' => $libraryId->toString(),
            'tmdb-id' => '45',
            '--refresh-credits' => true,
            '--json' => true,
        ]));
        self::assertSame([
            'publicId' => $title->publicId->toString(),
            'name' => 'A series',
            'jobId' => 'tv-series-job',
        ], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testEpisodeCommandAcceptsSpecialsAndPreservesNumbers(): void
    {
        $seriesId = new PublicId();
        $title = new TvTitleRecord(Uuid::v7(), new PublicId(), Uuid::v7(), 45, 'A special', 0, 2);
        $jobs = $this->createMock(JobMonitorAdministrationInterface::class);
        $jobs->expects(self::once())->method('runInline')
            ->with(self::callback(static fn(object $message): bool => $message instanceof RegisterTvEpisodeCommand
                && $message->seriesId->equals($seriesId)
                && $message->seasonNumber === 0
                && $message->episodeNumber === 2))
            ->willReturn(new InlineJobRun('tv-episode-job', $title));
        $tester = new CommandTester(new TvEpisodeRegisterCommand($jobs));
        self::assertSame(0, $tester->execute([
            'series' => $seriesId->toString(),
            'season' => '0',
            'episode' => '2',
            '--json' => true,
        ]));
        self::assertSame('A special', json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['name']);
    }

    public function testInvalidArgumentDoesNotStartAJob(): void
    {
        $jobs = $this->createMock(JobMonitorAdministrationInterface::class);
        $jobs->expects(self::never())->method('runInline');
        $tester = new CommandTester(new TvSeriesRegisterCommand($jobs));
        self::assertSame(2, $tester->execute([
            'library' => Uuid::v7()->toString(),
            'tmdb-id' => '12x',
        ]));
    }

    public function testMonitoredRegistrationMessagesRoundTripThroughTheirCodec(): void
    {
        $codec = new CatalogMessagePayloadCodec();
        $series = new RegisterTvSeriesCommand(Uuid::v7(), 123, true);
        $episode = new RegisterTvEpisodeCommand(new PublicId(), 0, 1, true);
        foreach ([
            'catalog.tv_series_register' => $series,
            'catalog.tv_episode_register' => $episode,
        ] as $type => $message) {
            $payload = array_combine($codec->fields($type), $codec->encode($message));
            self::assertEquals($message, $codec->decode($type, $payload));
        }
    }
}
