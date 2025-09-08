<?php

declare(strict_types=1);

namespace App\Console\Commands\OAuth;

use App\Models\OAuth\Scope;
use Illuminate\Console\Command;

class InstallScopesCommand extends Command
{
    protected $signature = 'oauth:scope:install';
    protected $description = 'Install default OAuth 2.0 scopes';

    public function handle(): int
    {
        $scopes = config('oauth.default_scopes', []);

        if (empty($scopes)) {
            $this->info('No default scopes configured.');
            return self::SUCCESS;
        }

        $this->info('Installing default OAuth scopes...');

        $created = 0;
        foreach ($scopes as $id => $description) {
            $scope = Scope::firstOrCreate(
                ['id' => $id],
                ['description' => $description]
            );

            if ($scope->wasRecentlyCreated) {
                $this->line("✓ Created scope: {$id}");
                $created++;
            } else {
                $this->line("- Scope exists: {$id}");
            }
        }

        $this->info("Installed {$created} new scopes.");

        return self::SUCCESS;
    }
}
