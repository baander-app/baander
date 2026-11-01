<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain\Playback;

use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlaybackControlContractTest extends TestCase
{
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
