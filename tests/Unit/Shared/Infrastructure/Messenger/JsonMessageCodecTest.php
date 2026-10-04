<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Tests\Fixtures\Messaging\MessageCodecFactory;
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
use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use App\Shared\Domain\Event\Outbox\RelayOutboxCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messaging\OutboxMessagePayloadCodec;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonMessageCodecTest extends TestCase
{
    public function testDuplicateWireRegistrationsFailBeforeReceivingMessages(): void
    {
        $this->expectException(\LogicException::class);
        new JsonMessageCodec([new OutboxMessagePayloadCodec(), new OutboxMessagePayloadCodec()]);
    }

    public function testMissingFeatureCodecCannotDecodeOrInstantiateItsMessages(): void
    {
        $codec = new JsonMessageCodec([new OutboxMessagePayloadCodec()]);
        $wire = MessageCodecFactory::create()->encode(new ExtractAlbumCoverCommand(Uuid::generate()));

        $this->expectException(\InvalidArgumentException::class);
        $codec->decode($wire);
    }

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
        yield [new SendEmailCommand($id, 'test@baander.app', NotificationCategory::Security, 'title', 'body', new \DateTimeImmutable('2026-10-01T12:00:00.000+00:00'))];
        yield [new SendPushCommand($id, NotificationCategory::Security, 'title', 'body', 'notification')];
        yield [new SendWebhookCommand($id, NotificationCategory::Security, 'title', 'body', 'notification')];
        yield [new PruneMissingImagesCommand()];
        yield [new SyncCountryStationsCommand($id, 'de')];
        yield [new ExecuteScheduledJobCommand('job-id', 'command', 'command-name', ['limit' => 3])];
        yield [new ExecuteScheduledOccurrenceCommand($id)];
        yield [new RelayOutboxCommand(50)];
        yield [new UpdateTranscodePositionCommand($id, 12.5, 'seek')];
    }

    #[DataProvider('messages')]
    public function testEveryRoutedMessageRoundTripsWithoutClassMetadata(object $message): void
    {
        $codec = MessageCodecFactory::create();
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
        $codec = MessageCodecFactory::create();
        $json = '{"format":"baander.message","version":1,"type":"metadata.extract_album_cover","payload":{"album_id":"00000000-0000-4000-8000-000000000001"},"metadata":{}}';
        self::assertSame($json, $codec->encode(new ExtractAlbumCoverCommand(Uuid::fromString('00000000-0000-4000-8000-000000000001'))));
        self::assertInstanceOf(ExtractAlbumCoverCommand::class, $codec->decode($json)->message);
    }

    public function testOccurrenceWireCarriesOnlyItsIdentity(): void
    {
        $id = Uuid::v7();
        $codec = MessageCodecFactory::create();
        $wire = json_decode($codec->encode(new ExecuteScheduledOccurrenceCommand($id)), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('scheduler.execute_occurrence', $wire['type']);
        self::assertSame(['occurrence_id' => $id->toString()], $wire['payload']);
        $wire['payload']['command'] = 'app:untrusted-override';
        $this->expectException(\InvalidArgumentException::class);
        $codec->decode(json_encode($wire, JSON_THROW_ON_ERROR));
    }

    public function testOccurrenceWireRejectsInvalidIdentity(): void
    {
        $codec = MessageCodecFactory::create();
        $wire = json_decode($codec->encode(new ExecuteScheduledOccurrenceCommand(Uuid::v7())), true, flags: JSON_THROW_ON_ERROR);
        $wire['payload']['occurrence_id'] = 'not-a-uuid';
        $this->expectException(\InvalidArgumentException::class);
        $codec->decode(json_encode($wire, JSON_THROW_ON_ERROR));
    }

    public function testSchedulerParametersPreserveNumericTypesAndArgumentOrder(): void
    {
        $parameters = [
            'integer' => 1,
            'integral_float' => 1.0,
            'fraction' => 1.25,
            'large_exponent' => 1.0e18,
            'small_exponent' => 1.0e-7,
            'zero' => 0.0,
            'negative_zero' => -0.0,
            'nested' => [
                'list' => [1, 1.0, -0.0],
                'positional' => [2 => 'first', 0 => 'second'],
                'named' => ['z' => 1.0, 'a' => 2.0],
            ],
        ];
        $codec = MessageCodecFactory::create();
        $encoded = $codec->encode(new ExecuteScheduledJobCommand('job-id', 'command', 'command-name', $parameters));
        $decoded = $codec->decode($encoded)->message;

        self::assertInstanceOf(ExecuteScheduledJobCommand::class, $decoded);
        self::assertSame($parameters, $decoded->parameters);
        // Strict array equality does not distinguish the sign of floating-point zero.
        self::assertSame(
            json_encode($parameters, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            json_encode($decoded->parameters, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        );
        self::assertStringContainsString('"negative_zero":-0.0', $encoded);
    }

    public function testMetadataPreservesFloatTokensAndNestedOrder(): void
    {
        $metadata = ['integral' => 1.0, 'negative_zero' => -0.0, 'nested' => ['z' => 1.0e18, 'a' => [1.25, 0.0]]];
        $codec = MessageCodecFactory::create();
        $encoded = $codec->encode(new RelayOutboxCommand(1), $metadata);
        $decoded = $codec->decode($encoded);

        self::assertSame($metadata, $decoded->metadata);
        self::assertSame(
            json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            json_encode($decoded->metadata, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        );
        self::assertStringContainsString('"negative_zero":-0.0', $encoded);
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
        (MessageCodecFactory::create())->decode($json);
    }

    public function testUnknownObjectsCannotBeEncoded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (MessageCodecFactory::create())->encode(new \stdClass());
    }

    public function testWholeDocumentSizeIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (MessageCodecFactory::create(50))->encode(new RelayOutboxCommand(1));
    }
}
