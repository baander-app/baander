<?php

namespace App\Console\Commands\Auth;

use App\Models\PersonalAccessToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ManageSanctumTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sanctum:tokens
                            {action : Action to perform (clean, cache, clear)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage Sanctum tokens (clean expired, cache all, clear cache)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'clean':
                return $this->cleanExpiredTokens();
            case 'cache':
                return $this->cacheAllTokens();
            case 'clear':
                return $this->clearTokenCache();
            case 'stats':
                return $this->showStats();
            default:
                $this->error("Unknown action: {$action}");
                return 1;
        }
    }

    /**
     * Remove expired tokens.
     */
    protected function cleanExpiredTokens()
    {
        $count = (new \App\Models\PersonalAccessToken)->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("{$count} expired tokens deleted");
        return 0;
    }

    /**
     * Cache all active tokens.
     */
    protected function cacheAllTokens()
    {
        $cacheConfig = config('sanctum.token_cache');

        if (!$cacheConfig['enabled']) {
            $this->error("Token caching is disabled in config");
            return 1;
        }

        $tokens = (new \App\Models\PersonalAccessToken)->whereNull('expires_at')
            ->orWhere('expires_at', '>', now())
            ->get();

        $count = 0;
        $ttl = is_numeric($cacheConfig['ttl']) ? (int) $cacheConfig['ttl'] : null;

        foreach ($tokens as $token) {
            $cacheKey = $cacheConfig['prefix'] . $token->id;

            Cache::store($cacheConfig['store'])->put(
                $cacheKey,
                $token,
                $ttl ? now()->addMinutes($ttl) : null
            );

            $count++;
        }

        $this->info("{$count} tokens cached in Redis");
        return 0;
    }

    /**
     * Clear token cache.
     */
    protected function clearTokenCache()
    {
        $cacheConfig = config('sanctum.token_cache');
        $prefix = $cacheConfig['prefix'];

        // Safer approach - only clear tokens with our prefix
        $tokens = PersonalAccessToken::all();
        $count = 0;

        foreach ($tokens as $token) {
            $cacheKey = $prefix . $token->id;
            Cache::store($cacheConfig['store'])->forget($cacheKey);
            $count++;
        }

        $this->info("{$count} tokens cleared from cache");
        return 0;
    }

    /**
     * Show token stats.
     */
    protected function showStats()
    {
        $totalTokens = (new \App\Models\PersonalAccessToken)->count();
        $activeTokens = (new \App\Models\PersonalAccessToken)->whereNull('expires_at')
            ->orWhere('expires_at', '>', now())
            ->count();
        $expiredTokens = (new \App\Models\PersonalAccessToken)->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();

        $this->info("Token Statistics:");
        $this->table(
            ['Total Tokens', 'Active Tokens', 'Expired Tokens'],
            [[$totalTokens, $activeTokens, $expiredTokens]]
        );

        $cacheConfig = config('sanctum.token_cache');
        $this->info("Cache Configuration:");
        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', $cacheConfig['enabled'] ? 'Yes' : 'No'],
                ['Store', $cacheConfig['store']],
                ['Prefix', $cacheConfig['prefix']],
                ['TTL (minutes)', $cacheConfig['ttl'] ?? 'No expiration'],
            ]
        );

        return 0;
    }
}
