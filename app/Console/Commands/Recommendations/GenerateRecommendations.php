<?php

namespace App\Console\Commands\Recommendations;

use App\Modules\Recommendation\Services\RecommendationService;
use Illuminate\Console\Command;

class GenerateRecommendations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recommendations:generate 
                            {model : The model class to generate recommendations for}
                            {--name= : Optional specific recommendation name to generate}
                            {--fresh : Force regeneration of all recommendations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate model recommendations';

    /**
     * Execute the console command.
     *
     * @param RecommendationService $recommendationService
     * @return int
     */
    public function handle(RecommendationService $recommendationService)
    {
        $modelClass = $this->argument('model');
        $specificName = $this->option('name');
        $fresh = $this->option('fresh');

        if (!class_exists($modelClass)) {
            $this->error("Model class {$modelClass} does not exist");
            return 1;
        }

        $configMethod = 'getRecommendationConfig';
        if (!method_exists($modelClass, $configMethod)) {
            $this->error("Model {$modelClass} does not implement {$configMethod}");
            return 1;
        }

        $config = $modelClass::$configMethod();

        if (empty($config)) {
            $this->error("No recommendation configurations found for {$modelClass}");
            return 1;
        }

        $this->info("Generating recommendations for {$modelClass}");

        $totalCount = 0;

        if ($specificName) {
            if (!isset($config[$specificName])) {
                $this->error("Recommendation configuration '{$specificName}' not found");
                return 1;
            }

            $algorithm = $config[$specificName]['algorithm'] ?? 'unknown';
            $this->info("Generating '{$specificName}' recommendations using {$algorithm} algorithm");

            $count = $recommendationService->generateRecommendations($modelClass, $specificName);
            $this->info("Generated {$count} recommendations for '{$specificName}'");
            $totalCount += $count;
        } else {
            $this->info("Generating all recommendation types");

            foreach ($config as $name => $settings) {
                $algorithm = $settings['algorithm'] ?? 'unknown';
                $this->line("Generating '{$name}' recommendations using {$algorithm} algorithm");

                $count = $recommendationService->generateRecommendations($modelClass, $name);
                $this->line("Generated {$count} recommendations for '{$name}'");
                $totalCount += $count;
            }
        }

        $this->info("Finished generating a total of {$totalCount} recommendations");
        return 0;
    }
}
