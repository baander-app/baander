<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Catalog\Application\Dto\VideoSourceMetadata;
use App\Catalog\Interface\Resource\VideoPlaybackInformationResource;
use App\Shared\Domain\Model\Uuid;
use App\Catalog\Infrastructure\VideoPlaybackInformationService;
use App\Media\Application\Port\VideoQualityChoicesPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Transcode\Application\Port\VideoTimelinePreviewPort;
use PHPUnit\Framework\TestCase;

final class VideoPlaybackInformationServiceTest extends TestCase
{
    public function testInformationExposesAuthorizedSourceQualityChoicesThroughTheResource(): void
    {
        $id = new PublicId();
        $scope = LibraryReadScope::none();
        $source = new VideoSourceMetadata(
            videoId: new Uuid(),
            publicId: (string) $id,
            filePath: '/media/baander.mkv',
            filename: 'baander.mkv',
            size: 1000,
            mimeType: 'video/x-matroska',
            hash: 'hash',
            containerFormat: 'matroska',
            videoCodec: 'hevc',
            videoStreamIndex: 0,
            audioStreams: [],
            width: 1920,
            height: 1080,
            framerate: 24.0,
            duration: 60.0,
        );
        $videos = $this->createMock(VideoPortInterface::class);
        $videos->expects(self::once())->method('getSourceMetadata')->with($id, $scope)->willReturn($source);
        $videos->expects(self::once())->method('findByPublicId')->with($id)->willReturn(null);
        $previews = $this->createMock(VideoTimelinePreviewPort::class);
        $previews->expects(self::once())->method('describe')->with($source)->willReturn([
            'state' => 'unavailable', 'url' => null, 'interval' => 0.0,
            'width' => 160, 'height' => 90, 'columns' => 0, 'count' => 0,
        ]);
        $expected = [['id' => '720p', 'label' => '720p', 'width' => 1280, 'height' => 720]];
        $choices = $this->createMock(VideoQualityChoicesPortInterface::class);
        $choices->expects(self::once())->method('choices')->with(1920, 1080)->willReturn($expected);
        $information = (new VideoPlaybackInformationService($videos, $previews, $choices))->information($id, $scope);
        self::assertNotNull($information);
        self::assertSame($expected, VideoPlaybackInformationResource::from($information)['qualityChoices']);
    }

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
        $choices = $this->createMock(VideoQualityChoicesPortInterface::class);
        $choices->expects(self::never())->method('choices');
        $service = new VideoPlaybackInformationService($videos, $previews, $choices);
        self::assertNull($service->information($id, $scope));
        self::assertNull($service->image($id, $scope));
    }
}
