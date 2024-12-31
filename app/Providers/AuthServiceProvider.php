<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Gate::define('viewApiDoc', function ($user) {
            return $user->isAdmin();
        });

        Gate::define('viewDashboard', function ($user) {
            return $user->isAdmin();
        });

        Gate::define('executeJob', function ($user) {
            return $user->isAdmin();
        });
    }
}
