<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\TestCase;

final class LiveAudienceContractBoundaryTest extends TestCase
{
    use AnalysesDeptracFixtures;

    public function testAudienceContractsAreAvailableOnlyAtTheirDeclaredBoundaries(): void
    {
        $violations = self::deptracViolations(<<<'SOURCE'
<?php
namespace App\Auth\Application\Port;
interface CurrentUserAuthorityReader {}
namespace App\Auth\Application\DTO;
final class CurrentUserAuthority {}
namespace App\Auth\Application;
final class InternalAuthorityService {}
namespace App\Library\Application\Port;
interface LibraryAudienceReader {}
namespace App\Playlist\Application\Port;
interface PlaylistAudienceReader {}
namespace App\Auth\Infrastructure;
final class BoundaryAuthorityReader implements \App\Auth\Application\Port\CurrentUserAuthorityReader {}
namespace App\Library\Infrastructure;
final class BoundaryLibraryReader implements \App\Library\Application\Port\LibraryAudienceReader {}
namespace App\Playlist\Infrastructure;
final class BoundaryPlaylistReader implements \App\Playlist\Application\Port\PlaylistAudienceReader {}
namespace App\Shared\Infrastructure\LiveUpdates;
final class DoctrineLiveSnapshotReader {
    public function read(
        \App\Auth\Application\Port\CurrentUserAuthorityReader $authority,
        \App\Auth\Application\DTO\CurrentUserAuthority $result,
        \App\Library\Application\Port\LibraryAudienceReader $libraries,
        \App\Playlist\Application\Port\PlaylistAudienceReader $playlists,
    ): void {}
    public function internal(\App\Auth\Application\InternalAuthorityService $service): void {}
}
final class UnrelatedAdapter {
    public function read(
        \App\Auth\Application\Port\CurrentUserAuthorityReader $authority,
        \App\Library\Application\Port\LibraryAudienceReader $libraries,
        \App\Playlist\Application\Port\PlaylistAudienceReader $playlists,
    ): void {}
}
namespace App\Shared\Domain;
final class BoundaryModel {
    public function read(\App\Auth\Application\Port\CurrentUserAuthorityReader $authority): void {}
}
SOURCE);

        $expected = [
            'App\Shared\Infrastructure\LiveUpdates\DoctrineLiveSnapshotReader must not depend on App\Auth\Application\InternalAuthorityService',
            'App\Shared\Infrastructure\LiveUpdates\UnrelatedAdapter must not depend on App\Auth\Application\Port\CurrentUserAuthorityReader',
            'App\Shared\Infrastructure\LiveUpdates\UnrelatedAdapter must not depend on App\Library\Application\Port\LibraryAudienceReader',
            'App\Shared\Infrastructure\LiveUpdates\UnrelatedAdapter must not depend on App\Playlist\Application\Port\PlaylistAudienceReader',
            'App\Shared\Domain\BoundaryModel must not depend on App\Auth\Application\Port\CurrentUserAuthorityReader',
        ];
        sort($expected);
        sort($violations);
        self::assertSame($expected, $violations);
    }
}
