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
        ];

        self::assertSame($snapshot, PlaybackSnapshotResource::from($snapshot + [
            'user_id' => 'internal-account-key',
            'capability_hash' => 'internal-credential-hash',
        ]));
    }
}
