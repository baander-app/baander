<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ID3TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'id3:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws \Zend_Media_Id3_Exception
     */
    public function handle()
    {
        $file = '/Users/martin/Music/testlibrary/Headhunterz - Colors/01 - Headhunterz_Tatu - Colors.mp3';

        $id3 = new \Zend_Media_Id3v2($file);


        dd($id3->getFrames());
    }
}
