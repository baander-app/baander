<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Catalog\Infrastructure\VideoPlaybackInformationService;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Transcode\Application\Port\VideoTimelinePreviewPort;
use PHPUnit\Framework\TestCase;

final class VideoPlaybackInformationServiceTest extends TestCase
{
    public function testDeniedVideoDoesNotReadMetadataOrPreviewImage(): void
    {
        $id = new PublicId();
        $scope = LibraryReadScope::none();
        $videos = $this->createMock(VideoPortInterface::class);
        $videos->expects(self::exactly(2))->method('getSourceMetadata')->with($id, $scope)->willReturn(null);
        $videos->expects(self::never())->method('findByPublicId');
        $previews = $this->createMock(VideoTimelinePreviewPort::class);
        $previews->expects(self::never())->method('describe');
        $previews->expects(self::never())->method('image');
        $service = new VideoPlaybackInformationService($videos, $previews);
        self::assertNull($service->information($id, $scope));
        self::assertNull($service->image($id, $scope));
    }
}
