<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Infrastructure\Messaging;

use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Metadata\Application\Command\SyncMetadataCommand;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The job monitor stores the payload of the inline runs of app:lyrics:fetch and
 * app:metadata:sync so they can be retried from the monitor.
 */
final class InlineRunPayloadTest extends TestCase
{
    /** @return iterable<string, array{object}> */
    public static function messages(): iterable
    {
        yield 'bulk fetch without a limit' => [new BulkFetchLyricsCommand()];
        yield 'bulk fetch with a limit and delay' => [new BulkFetchLyricsCommand(limit: 10, delayMs: 1000)];
        yield 'metadata sync of every library' => [new SyncMetadataCommand()];
        yield 'genre sync' => [new SyncMetadataCommand('genres')];
    }

    #[DataProvider('messages')]
    public function testTheMessageRoundTripsThroughTheJsonCodec(object $message): void
    {
        $codec = MessageCodecFactory::create();

        self::assertEquals($message, $codec->decode($codec->encode($message))->message);
    }
}
