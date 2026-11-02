<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain\Playback;

use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\ObservedPlaybackState;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class LocalRecoveryTest extends TestCase
{
    public function testRecoveryReleasesOnlyTheProvenRunnerAndRetainsMatchingCheckpoint(): void
    {
        $media = new PlaybackMedia('song', new PublicId());
        $session = ListeningSession::create(new Uuid(), [], 0, 0);
        $session->control('load', $media, [$media->publicId->toString()], 0, 12, PlaybackState::Playing);
        $session->commitDestination(new Uuid(), new Uuid(), PlaybackState::Playing, 12);
        $revision = $session->revision();

        $session->recoverLocalRunner($media, 0, $revision, 17.5, true, true);

        self::assertNull($session->activeIncarnationId());
        self::assertNull($session->selectedDestinationId());
        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame(ObservedPlaybackState::Paused, $session->observedState());
        self::assertSame(17.5, $session->getPosition());
        self::assertSame($revision + 1, $session->revision());
    }

    public function testCheckpointFromAnotherSelectionCannotOverwriteTheRetainedCursor(): void
    {
        $media = new PlaybackMedia('video', new PublicId());
        $session = ListeningSession::create(new Uuid(), [], 0, 0);
        $session->control('load', $media, [], 0, 21, PlaybackState::Playing);

        $session->recoverLocalRunner(new PlaybackMedia('video', new PublicId()), 0, $session->revision(), 88, true, true);

        self::assertSame(21.0, $session->getPosition());
        self::assertSame($media, $session->media());
    }

    public function testCheckpointRevisionAndQueuePositionMustMatchBeforeApplyingItsCursor(): void
    {
        $media = new PlaybackMedia('song', new PublicId());
        foreach ([[0, 0], [1, 1]] as [$revision, $index]) {
            $session = ListeningSession::create(new Uuid(), [], 0, 0);
            $session->control('load', $media, [$media->publicId->toString()], 0, 12, PlaybackState::Playing);

            $session->recoverLocalRunner($media, $index, $revision, 88, true, true);

            self::assertSame(12.0, $session->getPosition());
        }
    }

    public function testUnprovenSourceRemainsSelectedAndUnknownUntilItAcknowledgesStop(): void
    {
        $session = ListeningSession::create(new Uuid(), [], 0, 4);
        $source = new Uuid();
        $destination = new Uuid();
        $session->commitDestination($source, $destination, PlaybackState::Playing, 4);

        $session->recoverLocalRunner(null, 0, $session->revision(), 99, false, false);

        self::assertSame($source, $session->activeIncarnationId());
        self::assertSame($destination, $session->selectedDestinationId());
        self::assertSame(PlaybackState::Paused, $session->desiredState());
        self::assertSame(ObservedPlaybackState::Unknown, $session->observedState());
        self::assertSame(4.0, $session->getPosition());
    }
}
