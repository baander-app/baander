<?php

namespace Tests\FFMpegStreaming;

use Baander\Ffmpeg\DASH;
use Baander\Ffmpeg\HLS;
use Baander\Ffmpeg\Media;

class MediaTest extends TestCase
{
    public function testMediaClass()
    {
        $media = $this->getVideo();
        $this->assertInstanceOf(Media::class, $media);
    }

    public function testDASH()
    {
        $this->assertInstanceOf(DASH::class, $this->getDASH());
    }

    private function getDASH()
    {
        $media = $this->getVideo();
        return $media->DASH();
    }

    public function testHLS()
    {
        $this->assertInstanceOf(HLS::class, $this->getHLS());
    }

    private function getHLS()
    {
        $media = $this->getVideo();
        return $media->HLS();
    }

    public function testGetPath()
    {
        $media = $this->getVideo();
        $get_path_info = pathinfo($media->getPathfile());

        $this->assertIsArray($get_path_info);
        $this->assertArrayHasKey('dirname', $get_path_info);
        $this->assertArrayHasKey('filename', $get_path_info);
    }
}