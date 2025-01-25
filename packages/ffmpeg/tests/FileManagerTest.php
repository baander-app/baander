<?php

namespace Tests\FFMpegStreaming;

use Baander\Ffmpeg\File;

class FileManagerTest extends TestCase
{
    public function testMakeDir()
    {
        $path = $this->srcDir . DIRECTORY_SEPARATOR . "test_make_dir";
        File::makeDir($path);

        $this->assertDirectoryExists($path);
    }

    public function testTmp()
    {
        $tmp_file = File::tmp();
        $tmp_dir = File::tmpDir();

        $this->assertIsString($tmp_file);
        $this->assertIsString($tmp_dir);
    }
}
