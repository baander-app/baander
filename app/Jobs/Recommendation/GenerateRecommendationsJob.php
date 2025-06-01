<?php

namespace App\Jobs\Recommendation;

use App\Modules\Recommendation\Services\RecommendationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateRecommendationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The model class to generate recommendations for
     *
     * @var string
     */
    public string $modelClass;

    /**
     * The name of the recommendation set
     *
     * @var string
     */
    public string $recommendationName;

    /**
     * Create a new job instance.
     *
     * @param string $modelClass
     * @param string $recommendationName
     * @return void
     */
    public function __construct(string $modelClass, string $recommendationName)
    {
        $this->modelClass = $modelClass;
        $this->recommendationName = $recommendationName;

        // Set queue connection and name from config
        $this->onConnection(config('recommendation.queue.connection'));
        $this->onQueue(config('recommendation.queue.queue', 'recommendations'));
    }

    /**
     * Execute the job.
     *
     * @param RecommendationService $recommendationService
     * @return void
     */
    public function handle(RecommendationService $recommendationService): void
    {
        $recommendationService->generateRecommendations(
            $this->modelClass,
            $this->recommendationName
        );
    }
}
