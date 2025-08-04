<?php

namespace App\Console\Commands\Recommendations;

use App\Models\Song;
use App\Models\User;
use Illuminate\Console\Command;

class SynchronizeRecommendationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recommendation:sync';

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
        Song::generateRecommendations('similar_genre', ['user_id' => (new \App\Models\User)->first()->id]);
    }
}
