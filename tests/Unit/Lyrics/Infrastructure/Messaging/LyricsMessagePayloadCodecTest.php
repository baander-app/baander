<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lyrics\Infrastructure\Messaging;

use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use PHPUnit\Framework\TestCase;

/** A queued lyrics fetch keeps the bulk run that queued it through the async transport. */
final class LyricsMessagePayloadCodecTest extends TestCase
{
    public function testAFetchOfABulkRunRoundTripsWithItsRun(): void
    {
        $codec = MessageCodecFactory::create();
        $message = new FetchLyricsCommand(Uuid::v7(), Uuid::v7());

        $encoded = $codec->encode($message);
        $payload = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR)['payload'];

        self::assertSame(
            ['song_id' => $message->getSongId()->toString(), 'bulk_run_id' => $message->getBulkRunId()?->toString()],
            $payload,
        );
        self::assertEquals($message, $codec->decode($encoded)->message);
    }

    public function testAFetchOutsideABulkRunRoundTripsWithoutARun(): void
    {
        $codec = MessageCodecFactory::create();
        $message = new FetchLyricsCommand(Uuid::v7());

        $decoded = $codec->decode($codec->encode($message))->message;

        self::assertEquals($message, $decoded);
        self::assertInstanceOf(FetchLyricsCommand::class, $decoded);
        self::assertNull($decoded->getBulkRunId());
    }
}
