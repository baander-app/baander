<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Interface\Resource;

use App\Session\Interface\Resource\PlaybackSnapshotResource;
use PHPUnit\Framework\TestCase;

final class PlaybackSnapshotResourceTest extends TestCase
{
    public function testOnlyExplicitPlaybackFieldsLeaveTheReadModel(): void
    {
        $snapshot = [
            'schemaVersion' => 1,
            'publicId' => 'playbackSession0000001',
            'revision' => 4,
            'media' => ['kind' => 'song', 'publicId' => 'playbackSong000000001'],
            'queue' => ['playbackSong000000001'],
            'currentTrackIndex' => 0,
            'position' => 12.5,
            'playbackState' => 'paused',
            'desiredState' => 'paused',
            'observedState' => 'paused',
            'selectedDestinationId' => null,
            'activeDestinationId' => null,
            'activeIncarnationId' => null,
            'transfer' => null,
            'destinations' => [],
            'commands' => [],
            'requestResult' => ['requestId' => '019aa4dd-20f8-79a7-9b88-a0f38222b016',
                'commandId' => '019aa4dd-20f8-79a7-9b88-a0f38222b017', 'outcome' => 'rejected',
                'rejectionReason' => 'browser_rejected_source'],
            'transferResult' => ['requestId' => '019aa4dd-20f8-79a7-9b88-a0f38222b018',
                'attemptId' => '019aa4dd-20f8-79a7-9b88-a0f38222b019', 'state' => 'failed',
                'rejectionReason' => 'video_codec_unsupported'],
        ];

        self::assertSame($snapshot, PlaybackSnapshotResource::from($snapshot + [
            'user_id' => 'internal-account-key',
            'capability_hash' => 'internal-credential-hash',
        ]));
    }
}
