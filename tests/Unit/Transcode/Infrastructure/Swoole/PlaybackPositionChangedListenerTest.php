<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Infrastructure\Swoole\PlaybackPositionChangedListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class PlaybackPositionChangedListenerTest extends TestCase
{
    /** @return iterable<array{string}> */
    public static function actions(): iterable
    {
        yield ['seek'];
        yield ['pause'];
        yield ['resume'];
    }

    #[DataProvider('actions')]
    public function testPartyControlsEnterDurableSessionCommand(string $action): void
    {
        $jobId = Uuid::generate();
        $sessionId = Uuid::generate();
        $runtime = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $runtime->method('find')->willReturn(new TranscodeRuntimeSnapshot($jobId, $sessionId, 'admission',
            new DesiredTranscodeState(AudioProfile::streamingStereo()), 1, 1, 1, 'ready', '/cache/generation-1', null, true));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(static fn(object $command): bool =>
            $command instanceof UpdateTranscodePositionCommand && $command->sessionId->equals($sessionId)
            && $command->position === 30.5 && $command->action === $action))->willReturnCallback(static fn(object $command): Envelope => new Envelope($command));
        (new PlaybackPositionChangedListener($runtime, $bus))(new PlaybackPositionChanged($jobId, 30.5, $action));
    }

    public function testPartyWithoutExecutionIntentDoesNotStartAnEncoder(): void
    {
        $runtime = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $runtime->method('find')->willReturn(null);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        (new PlaybackPositionChangedListener($runtime, $bus))(new PlaybackPositionChanged(Uuid::generate(), 0.0, 'seek'));
    }
}
