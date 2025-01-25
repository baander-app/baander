<?php

namespace Tests\FFMpegStreaming;

use Baander\Ffmpeg\Filters\HLSFilter;
use Baander\Ffmpeg\Filters\StreamFilter;
use Baander\Ffmpeg\HLS;

class HLSFiltersTest extends TestCase
{
    public function testFilterClass()
    {
        $this->assertInstanceOf(StreamFilter::class, $this->getFilter());
    }

    private function getFilter()
    {
        return new HLSFilter($this->getHLS());
    }

    private function getHLS()
    {
        $hls = new HLS($this->getVideo());

        return $hls->X264()
            ->autoGenerateRepresentations()
            ->setHlsAllowCache(false)
            ->setHlsTime(10);
    }
}