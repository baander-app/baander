<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Domain\Model\DiscoveredFile;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Media\Application\Command\PruneMissingImagesCommand;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Application\DTO\SendWebhookCommand;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Radio\Application\Command\SyncCountryStationsCommand;
use App\Scheduler\Application\Command\ExecuteScheduledJobCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonMessageCodecTest extends TestCase
{
    /** @return iterable<array{object}> */
    public static function messages(): iterable
    {
        $id = Uuid::fromString('00000000-0000-4000-8000-000000000001');
        yield [new ScanLibraryCommand(new LibrarySlug('music'), true)];
        yield [new FilesDiscovered($id, 'music', '/music', [new DiscoveredFile('/music/a.flac', 'a.flac', 'flac', 100, 12345, 'abc')])];
        yield [new ExtractAlbumCoverCommand($id)];
        yield [new SyncSongMessage($id, true)];
        yield [new SyncAlbumMessage($id)];
        yield [new SyncLibraryMessage($id, true, true, true)];
        yield [new SendEmailCommand($id, 'test@example.com', NotificationCategory::Security, 'title', 'body', new \DateTimeImmutable('2026-10-01T12:00:00.000+00:00'))];
        yield [new SendPushCommand($id, NotificationCategory::Security, 'title', 'body', 'notification')];
        yield [new SendWebhookCommand($id, NotificationCategory::Security, 'title', 'body', 'notification')];
        yield [new PruneMissingImagesCommand()];
        yield [new SyncCountryStationsCommand($id, 'de')];
        yield [new ExecuteScheduledJobCommand('job-id', 'command', 'command-name', ['limit' => 3])];
        yield [new RelayOutboxCommand(50)];
        yield [new UpdateTranscodePositionCommand($id, 12.5, 'seek')];
    }

    #[DataProvider('messages')]
    public function testEveryRoutedMessageRoundTripsWithoutClassMetadata(object $message): void
    {
        $codec = new JsonMessageCodec();
        $encoded = $codec->encode($message, ['correlation_id' => 'correlation']);
        self::assertStringNotContainsString('Symfony', $encoded);
        self::assertStringNotContainsString('App\\', $encoded);
        self::assertStringNotContainsString('__class', $encoded);
        $decoded = $codec->decode($encoded);
        self::assertEquals($message, $decoded->message);
        self::assertSame(['correlation_id' => 'correlation'], $decoded->metadata);
    }

    public function testWireFormatHasAStableGoldenExample(): void
    {
        $codec = new JsonMessageCodec();
        $json = '{"format":"baander.message","version":1,"type":"metadata.extract_album_cover","payload":{"album_id":"00000000-0000-4000-8000-000000000001"},"metadata":{}}';
        self::assertSame($json, $codec->encode(new ExtractAlbumCoverCommand(Uuid::fromString('00000000-0000-4000-8000-000000000001'))));
        self::assertInstanceOf(ExtractAlbumCoverCommand::class, $codec->decode($json)->message);
    }

    /** @return iterable<array{string}> */
    public static function invalidDocuments(): iterable
    {
        yield ['not json'];
        yield ['{"__class":"stdClass"}'];
        yield ['{"format":"baander.message","version":2,"type":"outbox.relay","payload":{"batch_size":1},"metadata":{}}'];
        yield ['{"format":"baander.message","version":1,"type":"unknown","payload":{},"metadata":{}}'];
        yield ['{"format":"baander.message","version":1,"type":"outbox.relay","payload":{"batch_size":"1"},"metadata":{}}'];
        yield ['{"format":"baander.message","version":1,"type":"outbox.relay","payload":{"batch_size":1,"extra":true},"metadata":{}}'];
        yield ['{"format":"baander.message","version":1,"type":"outbox.relay","payload":{},"metadata":{}}'];
        yield [str_repeat('[', 33) . '0' . str_repeat(']', 33)];
    }

    #[DataProvider('invalidDocuments')]
    public function testMalformedOrUnsupportedContractsAreRejected(string $json): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new JsonMessageCodec())->decode($json);
    }

    public function testUnknownObjectsCannotBeEncoded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new JsonMessageCodec())->encode(new \stdClass());
    }

    public function testWholeDocumentSizeIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new JsonMessageCodec(50))->encode(new RelayOutboxCommand(1));
    }
}
