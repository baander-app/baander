<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Video;
use App\Catalog\Interface\Resource\VideoResource;
use PHPUnit\Framework\TestCase;

final class VideoResourceTest extends TestCase
{
    public function testTheFullProjectionCarriesTheFilePath(): void
    {
        self::assertSame('/media/movies/film.mkv', VideoResource::from($this->video())['path']);
    }

    public function testAMemberReadsEveryFieldExceptTheFilePath(): void
    {
        $video = $this->video();

        $member = VideoResource::forMember($video);

        self::assertArrayNotHasKey('path', $member);
        self::assertStringNotContainsString('/media/movies/film.mkv', json_encode($member, JSON_THROW_ON_ERROR));
        self::assertSame(array_diff_key(VideoResource::from($video), ['path' => true]), $member);
    }

    private function video(): Video
    {
        return Video::create('/media/movies/film.mkv', 'hash', duration: 5400, height: 1080, width: 1920);
    }
}
