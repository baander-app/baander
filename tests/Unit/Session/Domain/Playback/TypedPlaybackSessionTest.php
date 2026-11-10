<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain\Playback;

use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TypedPlaybackSessionTest extends TestCase
{
    public function testPausedVideoRetainsMusicQueueWhenQueueIsAbsent(): void
    {
        $queue = [new PublicId()->toString(), new PublicId()->toString()];
        $session = ListeningSession::create(new Uuid(), $queue, 1, 18.25);
        $identity = $session->getPublicId();
        $video = new PlaybackMedia('video', new PublicId());
        $session->control('load', $video, null, null, 82.125, PlaybackState::Paused);
        self::assertSame($queue, $session->getQueue());
        self::assertSame(1, $session->getCurrentTrackIndex());
        self::assertSame($video, $session->media());
        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame(82.125, $session->getPosition());
        self::assertSame($identity, $session->getPublicId());
    }

    public function testQueueShrinkRequiresAValidCursorAndPreservesStateOnFailure(): void
    {
        $queue = [new PublicId()->toString(), new PublicId()->toString()];
        $session = ListeningSession::create(new Uuid(), $queue, 1, 18.25);
        try {
            $session->control('pause', null, [$queue[0]], null, null, null);
            self::fail('Shrinking the queue must not leave an invalid cursor.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('queue cursor', $error->getMessage());
        }
        self::assertSame($queue, $session->getQueue());
        self::assertSame(0, $session->revision());
        $session->control('pause', null, [$queue[0]], 0, null, null);
        self::assertSame([$queue[0]], $session->getQueue());
        self::assertSame(0, $session->getCurrentTrackIndex());
    }
    public function testStopRetainsMediaQueueAndCursorUntilExplicitResume(): void
    {
        $queue = [new PublicId()->toString()];
        $media = new PlaybackMedia('song', PublicId::fromString($queue[0]));
        $session = ListeningSession::create(new Uuid(), $queue, 0, 0);
        $session->control('load', $media, null, null, 42.5, PlaybackState::Playing);
        $session->control('stop', null, null, null, null, null);
        self::assertSame(PlaybackState::Stopped, $session->desiredState());
        self::assertSame($media, $session->media());
        self::assertSame($queue, $session->getQueue());
        self::assertSame(42.5, $session->getPosition());
        $session->control('play', null, null, null, null, null);
        self::assertSame(PlaybackState::Playing, $session->desiredState());
        self::assertSame(42.5, $session->getPosition());
    }

    public function testVolumeIntentCannotChangePlaybackStateAndRejectsOutOfRangeValues(): void
    {
        $queue = [new PublicId()->toString()];
        $session = ListeningSession::create(new Uuid(), $queue, 0, 42.5);
        foreach ([-0.1, 1.1, INF, NAN] as $volume) {
            try {
                $session->control('volume', null, null, null, null, null, $volume);
                self::fail('Volume must remain a finite normalized fraction.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('Volume', $error->getMessage());
            }
        }
        self::assertSame(0, $session->revision());
        $session->control('volume', null, null, null, null, null, 0.0);
        self::assertSame(1, $session->revision());
        self::assertSame(PlaybackState::Stopped, $session->desiredState());
        self::assertSame($queue, $session->getQueue());
        self::assertSame(42.5, $session->getPosition());
    }

}
