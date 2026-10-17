<?php

declare(strict_types=1);

namespace App\Tests\Unit\Playlist\Domain\Model;

use App\Playlist\Domain\Model\PlaylistPersistenceSnapshot;
use App\Playlist\Domain\Model\PlaylistSong;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class PlaylistPersistenceSnapshotTest extends TestCase
{
    public function testRevisionTracksMembershipAndPositionsIndependentlyOfHydrationOrder(): void
    {
        $first = new Uuid();
        $second = new Uuid();
        $collaborator = new Uuid();
        $otherCollaborator = new Uuid();
        $songs = [new PlaylistSong($first, 0), new PlaylistSong($second, 1)];
        $original = PlaylistPersistenceSnapshot::capture(3, $songs, [$collaborator, $otherCollaborator]);
        $same = PlaylistPersistenceSnapshot::capture(3, array_reverse($songs), [$otherCollaborator, $collaborator]);
        $scalar = PlaylistPersistenceSnapshot::fromMembership(
            3,
            [$second->toString() => 1, $first->toString() => 0],
            [$otherCollaborator->toString(), $collaborator->toString()],
        );
        $reordered = PlaylistPersistenceSnapshot::capture(3, [new PlaylistSong($first, 1), new PlaylistSong($second, 0)], [$collaborator, $otherCollaborator]);
        $songCascade = PlaylistPersistenceSnapshot::capture(3, [$songs[0]], [$collaborator, $otherCollaborator]);
        $userCascade = PlaylistPersistenceSnapshot::capture(3, $songs, [$collaborator]);

        self::assertSame($original->revision(), $same->revision());
        self::assertSame($original->revision(), $scalar->revision());
        self::assertTrue($original->hasSameMembership($same));
        foreach ([$reordered, $songCascade, $userCascade] as $changed) {
            self::assertNotSame($original->revision(), $changed->revision());
            self::assertFalse($original->hasSameMembership($changed));
        }
        self::assertNotSame($original->revision(), PlaylistPersistenceSnapshot::capture(4, $songs, [$collaborator, $otherCollaborator])->revision());
    }
}
