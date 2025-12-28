<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Commands;

use App\Models\Auth\OAuth\AuthCode;
use App\Models\Auth\OAuth\DeviceCode;
use App\Models\Auth\OAuth\RefreshToken;
use App\Models\Auth\OAuth\Token;
use Illuminate\Console\Command;

class PruneExpiredTokensCommand extends Command
{
    protected $signature = 'oauth:prune
                            {--days=30 : Delete tokens expired more than N days ago}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Prune expired OAuth tokens and authorization codes';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run') !== false;
        $cutoffDate = now()->subDays($days);

        $this->info("Pruning OAuth tokens expired before: {$cutoffDate->toDateTimeString()}");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No data will be deleted');
            $this->newLine();
        }

        $totalDeleted = 0;

        // Prune expired access tokens
        $accessTokensQuery = Token::where('expires_at', '<', $cutoffDate);
        $accessTokensCount = $accessTokensQuery->count();
        $totalDeleted += $accessTokensCount;

        if ($accessTokensCount > 0) {
            $this->info("Found {$accessTokensCount} expired access tokens");

            if (!$dryRun) {
                $accessTokensQuery->delete();
                $this->comment('Deleted expired access tokens');
            }
        } else {
            $this->info('No expired access tokens found');
        }

        // Prune expired refresh tokens
        $refreshTokensQuery = RefreshToken::where('expires_at', '<', $cutoffDate);
        $refreshTokensCount = $refreshTokensQuery->count();
        $totalDeleted += $refreshTokensCount;

        if ($refreshTokensCount > 0) {
            $this->info("Found {$refreshTokensCount} expired refresh tokens");

            if (!$dryRun) {
                $refreshTokensQuery->delete();
                $this->comment('Deleted expired refresh tokens');
            }
        } else {
            $this->info('No expired refresh tokens found');
        }

        // Prune expired authorization codes
        $authCodesQuery = AuthCode::where('expires_at', '<', $cutoffDate);
        $authCodesCount = $authCodesQuery->count();
        $totalDeleted += $authCodesCount;

        if ($authCodesCount > 0) {
            $this->info("Found {$authCodesCount} expired authorization codes");

            if (!$dryRun) {
                $authCodesQuery->delete();
                $this->comment('Deleted expired authorization codes');
            }
        } else {
            $this->info('No expired authorization codes found');
        }

        // Prune expired device codes
        $deviceCodesQuery = DeviceCode::where('expires_at', '<', $cutoffDate);
        $deviceCodesCount = $deviceCodesQuery->count();
        $totalDeleted += $deviceCodesCount;

        if ($deviceCodesCount > 0) {
            $this->info("Found {$deviceCodesCount} expired device codes");

            if (!$dryRun) {
                $deviceCodesQuery->delete();
                $this->comment('Deleted expired device codes');
            }
        } else {
            $this->info('No expired device codes found');
        }

        $this->newLine();

        if ($dryRun) {
            $this->warn("Would delete a total of {$totalDeleted} records");
            $this->comment('Run without --dry-run to actually delete the records');
        } else {
            $this->info("Successfully deleted {$totalDeleted} expired records");
        }

        return self::SUCCESS;
    }
}
