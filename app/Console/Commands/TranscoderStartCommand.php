<?php

namespace App\Console\Commands;

use App\Modules\Transcoder\TranscoderContextFactory;
use Baander\Transcoder\ApplicationManager;
use Illuminate\Console\Command;

class TranscoderStartCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transcoder:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $context = TranscoderContextFactory::create();

        $manager = new ApplicationManager($context);

        $manager->getApplication()->run();
    }
}
