<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\Application\Command\BatchExtractCoversCommand;
use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Media\Application\Command\PruneMissingImagesCommand;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Radio\Application\Command\SyncCountryStationsCommand;
use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
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
            new ScanLibraryCommand(new LibrarySlug('music'), true),
            new PruneMissingImagesCommand(),
            new \App\Media\Application\Command\GenerateImageDerivativeCommand('image.png', 'thumb'),
            new \App\Recommendation\Application\Command\ExecuteRecommendationJobCommand($id),
            new ExtractAlbumCoverCommand($id),
            new SendPushCommand($id, NotificationCategory::Security, 'title', 'body', 'notification'),
            new SyncCountryStationsCommand($id, 'de'),
            new ExecuteScheduledOccurrenceCommand($id),
            new RelayOutboxCommand(50),
            new \App\Library\Application\Message\FilesDiscovered($id, 'music', '/media/music', []),
            new \App\Metadata\Application\Message\SyncSongMessage($id, true),
            new \App\Metadata\Application\Message\SyncAlbumMessage($id, true),
            new \App\Metadata\Application\Message\SyncLibraryMessage($id, true, true, true),
            new \App\Lyrics\Application\Command\FetchLyricsCommand($id),
            new \App\Notification\Application\DTO\SendEmailCommand($id, 'listener@baander.app', NotificationCategory::Security, 'title', [], 'body', [], new \DateTimeImmutable('2026-10-18T04:00:00Z')),
            new UpdateTranscodePositionCommand($id, 12.5, 'seek'),
        ];

        self::assertCount(17, $messages);
        $catalog = self::getContainer()->get(\App\Shared\Infrastructure\Messaging\AsyncWorkloadCatalog::class);
        self::assertInstanceOf(\App\Shared\Infrastructure\Messaging\AsyncWorkloadCatalog::class, $catalog);
        $covered = array_fill_keys(array_map(static fn (object $message): string => $message::class, $messages), true);
        $probe = new \App\Tests\Fixtures\Messaging\FailureTransportProbe('round-trip', false);
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
