<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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
            new ScanLibraryCommand(new LibrarySlug('music'), true),
            new PruneMissingImagesCommand(),
            new ExtractAlbumCoverCommand($id),
            new SendPushCommand($id, NotificationCategory::Security, 'title', 'body', 'notification'),
            new SyncCountryStationsCommand($id, 'de'),
            new ExecuteScheduledOccurrenceCommand($id),
            new RelayOutboxCommand(50),
            new UpdateTranscodePositionCommand($id, 12.5, 'seek'),
        ];

        foreach ($messages as $message) {
            $wire = $codec->encode($message, ['correlation_id' => 'local-test']);
            self::assertSame($standalone->encode($message, ['correlation_id' => 'local-test']), $wire);
            self::assertEquals($message, $codec->decode($wire)->message);
        }
    }
}
