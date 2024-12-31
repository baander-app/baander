<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\LogFile;
use Opcodes\LogViewer\LogFolder;

class AuthorizationGateServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Gate::define('downloadLogFile', function (?User $user, LogFile $file) {
            return $user && $user->isAdmin();
        });

        Gate::define('downloadLogFolder', function (?User $user, LogFolder $folder) {
            return $user && $user->isAdmin();
        });

        Gate::define('deleteLogFile', function (?User $user, LogFile $file) {
            return $user && $user->isAdmin();
        });

        Gate::define('viewApiDocs', function (User $user) {
            return (bool)$user;
        });

        Gate::define('viewLogViewer', function (User $user) {
            return $user->isAdmin();
        });

    }
}
