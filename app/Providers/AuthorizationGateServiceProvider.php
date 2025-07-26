<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthorizationGateServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewApiDocs', function (User $user) {
            return (bool)$user;
        });

        Gate::define('viewLogViewer', function (User $user) {
            return $user->isAdmin();
        });

    }
}
