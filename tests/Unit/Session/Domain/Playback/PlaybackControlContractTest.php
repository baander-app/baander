<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain\Playback;

use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlaybackControlContractTest extends TestCase
{
    public function testQueueEditsPreserveCurrentPlaybackAndPosition(): void
    {
        $first = new PublicId();
        $second = new PublicId();
        $session = ListeningSession::create(new Uuid(), [$first->toString()], 0, 0);
        $media = new PlaybackMedia('song', $first);
        $session->control('load', $media, null, null, 42.5, PlaybackState::Playing);

        $session->control('queue', null, [$first->toString(), $second->toString()], 0, null, null);

        self::assertSame([$first->toString(), $second->toString()], $session->getQueue());
        self::assertSame($media, $session->media());
        self::assertSame(42.5, $session->getPosition());
        self::assertSame(PlaybackState::Playing, $session->desiredState());
    }

    public function testTransportActionsCannotOverrideTheirOwnPlaybackState(): void
    {
        $session = ListeningSession::create(new Uuid(), [], 0, 12);
        foreach (['play', 'pause', 'seek'] as $action) {
            try {
                $session->control($action, null, null, null, null, PlaybackState::Stopped);
                self::fail('Only loading media may supply a separate desired playback state.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $session->revision());
                self::assertSame(PlaybackState::Stopped, $session->desiredState());
            }
        }
    }
}
