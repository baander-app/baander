<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\CommandHandler;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use App\Transcode\Infrastructure\Messaging\TranscodeMessagePayloadCodec;
use PHPUnit\Framework\TestCase;

final class ProgressPositionCodecTest extends TestCase
{
    public function testProgressFenceSurvivesJsonTransport(): void
    {
        $command = new UpdateTranscodePositionCommand(Uuid::generate(), 72.5, 'progress', expectedGeneration: 3, expectedRevision: 5);
        $codec = new TranscodeMessagePayloadCodec();
        $values = json_decode(json_encode($codec->encode($command), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $decoded = $codec->decode('transcode.update_position', array_combine($codec->fields('transcode.update_position'), $values));
        self::assertInstanceOf(UpdateTranscodePositionCommand::class, $decoded);
        self::assertSame(3, $decoded->expectedGeneration);
        self::assertSame(5, $decoded->expectedRevision);
        self::assertSame(72.5, $decoded->position);
        self::assertSame('progress', $decoded->action);
        self::assertSame($command->requestId, $decoded->requestId);
    }
}
