<?php

namespace App\Console\Commands\Auth;

use App\Actions\Tokens\PruneExpiredTokens;
use App\Modules\Auth\AccessTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class PruneExpiredTokensCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:prune-expired-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune expired tokens';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $authTokenManager = app(AccessTokenService::class);
        $result = (new PruneExpiredTokens($authTokenManager))->run();

        $count = Arr::get($result, 'removed', 0);
        $this->warn("Pruned {$count} expired tokens.");

        if (app()->isLocal()) {
            dump($result['context']);
        }
    }
}
