<?php

namespace Tests\FFMpegStreaming;

use Baander\Ffmpeg\DASH;
use Baander\Ffmpeg\Format\StreamFormat;
use Baander\Ffmpeg\Representation;
use Baander\Ffmpeg\Stream;

class DASHTest extends TestCase
{
    public function testDASHClass()
    {
        $this->assertInstanceOf(Stream::class, $this->getDASH());
    }

    private function getDASH()
    {
        return new DASH($this->getVideo());
    }

    public function testFormat()
    {
        $dash = $this->getDASH();
        $dash->HEVC();

        $this->assertInstanceOf(StreamFormat::class, $dash->getFormat());
    }

    public function testAutoRepresentations()
    {
        $dash = $this->getDASH();
        $dash->HEVC()
            ->autoGenerateRepresentations();
        $representations = $dash->getRepresentations()->all();

        $this->assertIsArray($representations);
        $this->assertInstanceOf(Representation::class, current($representations));
        $this->assertEquals('256x144', $representations[0]->size2string());
        $this->assertEquals('426x240', $representations[1]->size2string());
        $this->assertEquals('640x360', $representations[2]->size2string());

        $this->assertEquals(103, $representations[0]->getKiloBitrate());
        $this->assertEquals(138, $representations[1]->getKiloBitrate());
        $this->assertEquals(207, $representations[2]->getKiloBitrate());
    }

    public function testSet()
    {
        $dash = $this->getDASH();
        $dash->setAdaption('test-adaption');

        $this->assertEquals('test-adaption', $dash->getAdaption());
    }

    public function testSave()
    {
        $dash = $this->getDASH();
        $export_class = $dash->HEVC()
            ->autoGenerateRepresentations()
            ->save($this->srcDir . '/dash/test.mpd');


        $this->assertFileExists($this->srcDir . '/dash/test.mpd');
        $this->assertInstanceOf(Stream::class, $export_class);
    }
}
