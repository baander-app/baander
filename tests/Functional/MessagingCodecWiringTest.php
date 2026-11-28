<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\Message\DrainVideoMatchesMessage;
use App\Catalog\Application\Message\RefreshVideoMatchMessage;
use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Media\Application\Command\GenerateImageDerivativeCommand;
use App\Media\Application\Command\PruneMissingImagesCommand;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Metadata\Application\Message\SyncArtistsMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncMovieMessage;
use App\Metadata\Application\Message\SyncPeopleMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendPushToSubscriptionCommand;
use App\Notification\Application\DTO\SendWebhookCommand;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Radio\Application\Command\SyncCountryStationsCommand;
use App\Recommendation\Application\Command\ExecuteRecommendationJobCommand;
use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messaging\AsyncWorkloadCatalog;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Tests\Fixtures\Messaging\FailureTransportProbe;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Transcode\Application\Command\PrepareTimelinePreview;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MessagingCodecWiringTest extends KernelTestCase
{
    public function testEveryFeatureCodecIsRegisteredInTheApplicationContainer(): void
    {
        self::bootKernel();
        $codec = self::getContainer()->get(JsonMessageCodec::class);
        self::assertInstanceOf(JsonMessageCodec::class, $codec);
        $standalone = MessageCodecFactory::create();
        $id = Uuid::generate();
        $messages = [
            new BatchExtractCoversCommand(),
            new DrainAlbumMatchesMessage('0199bf3c-8a00-7000-8000-c4a1d0000001', 2),
            new DrainVideoMatchesMessage(),
            new RefreshVideoMatchMessage(VideoKind::Movie, $id, Uuid::v7(), new \DateTimeImmutable('2026-11-28T12:00:00Z')),
            new ScanLibraryCommand(new LibrarySlug('music'), true),
            new PruneMissingImagesCommand(),
            new GenerateImageDerivativeCommand('image.png', 'thumb'),
            new ExecuteRecommendationJobCommand($id),
            new ExtractAlbumCoverCommand($id),
            new SendWebhookCommand($id, NotificationCategory::Security, 'title', 'body', 'notification'),
            new SendPushCommand($id, NotificationCategory::Security, 'title', 'body', 'notification'),
            new SendPushToSubscriptionCommand(Uuid::generate(), $id, 'title', 'body', 'notification'),
            new SyncCountryStationsCommand($id, 'de'),
            new ExecuteScheduledOccurrenceCommand($id),
            new RelayOutboxCommand(50),
            new FilesDiscovered($id, 'music', '/media/music', []),
            new SyncSongMessage($id, true),
            new SyncAlbumMessage($id, true),
            new SyncLibraryMessage($id, true, true),
            new SyncArtistsMessage(),
            new SyncArtistMessage($id, Uuid::generate(), 2),
            new SyncPeopleMessage('41'),
            new SyncMovieMessage($id, true),
            new FetchLyricsCommand($id),
            new SendEmailCommand($id, 'listener@baander.app', NotificationCategory::Security, 'title', [], 'body', [], new \DateTimeImmutable('2026-10-18T04:00:00Z')),
            new UpdateTranscodePositionCommand($id, 12.5, 'seek'),
            new PrepareTimelinePreview('/media/movies/film.mkv', 'film-preview', 5400.5, 12.5, str_repeat('a', 64), 0),
        ];

        self::assertCount(27, $messages);
        $catalog = self::getContainer()->get(AsyncWorkloadCatalog::class);
        self::assertInstanceOf(AsyncWorkloadCatalog::class, $catalog);
        $covered = array_fill_keys(array_map(static fn (object $message): string => $message::class, $messages), true);
        $probe = new FailureTransportProbe('round-trip', false);
        self::assertEquals($probe, $codec->decode($codec->encode($probe))->message);
        $covered[$probe::class] = true;
        self::assertSame([], array_diff_key($catalog->workloads(), $covered));

        foreach ($messages as $message) {
            $wire = $codec->encode($message, ['correlation_id' => 'local-test']);
            self::assertSame($standalone->encode($message, ['correlation_id' => 'local-test']), $wire);
            self::assertEquals($message, $codec->decode($wire)->message);
        }
    }
}
