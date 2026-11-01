<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application\Service;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Application\Service\PlaybackMediaAccess;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlaybackMediaAccessTest extends TestCase
{
    #[DataProvider('trackScopes')]
    public function testTrackAuthorizationUsesCurrentOwnerScope(string $scopeKind, bool $exists, bool $allowed): void
    {
        $owner = new Uuid();
        $track = new PublicId();
        $library = new Uuid();
        $scope = match ($scopeKind) {
            'admin' => LibraryReadScope::unrestricted(),
            'member' => LibraryReadScope::restricted([$library]),
            'other' => LibraryReadScope::restricted([new Uuid()]),
            default => LibraryReadScope::none(),
        };
        $scopes = $this->createMock(MediaReadScopeProviderInterface::class);
        $scopes->expects(self::once())->method('forUser')->with($owner)->willReturn(MediaReadScope::authenticated($owner, $scope));
        $streams = $this->createMock(StreamPortInterface::class);
        $streams->expects(self::once())->method('getLibraryIdForTrack')->with($track)->willReturn($exists ? $library : null);
        $streams->expects(self::never())->method('resolveTrackPath');
        $streams->expects(self::never())->method('getTrackMetadata');
        if (!$allowed) {
            $this->expectException(ConflictException::class);
            $this->expectExceptionMessage('Playback media is unavailable.');
        }
        new PlaybackMediaAccess($scopes, $streams, $this->createStub(VideoPortInterface::class))->assertTrackAccess($owner, $track);
        if ($allowed) {
            self::assertTrue($scope->allows($library));
        }
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function trackScopes(): iterable
    {
        yield 'member' => ['member', true, true];
        yield 'administrator' => ['admin', true, true];
        yield 'missing for administrator' => ['admin', false, false];
        yield 'missing for member' => ['member', false, false];
        yield 'revoked' => ['none', true, false];
        yield 'different library' => ['other', true, false];
    }

    #[DataProvider('videoVisibility')]
    public function testVideoAuthorizationDelegatesScopedExistence(bool $visible): void
    {
        $owner = new Uuid();
        $video = new PublicId();
        $scope = LibraryReadScope::restricted([new Uuid()]);
        $scopes = $this->createMock(MediaReadScopeProviderInterface::class);
        $scopes->expects(self::once())->method('forUser')->with($owner)->willReturn(MediaReadScope::authenticated($owner, $scope));
        $videos = $this->createMock(VideoPortInterface::class);
        $videos->expects(self::once())->method('isVisibleByPublicId')->with($video, $scope)->willReturn($visible);
        if (!$visible) {
            $this->expectException(ConflictException::class);
            $this->expectExceptionMessage('Playback media is unavailable.');
        }
        new PlaybackMediaAccess($scopes, $this->createStub(StreamPortInterface::class), $videos)->assertVideoAccess($owner, $video);
    }

    /** @return iterable<string, array{bool}> */
    public static function videoVisibility(): iterable
    {
        yield 'visible' => [true];
        yield 'missing or inaccessible' => [false];
    }
}
