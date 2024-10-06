<?php

namespace App\Providers;

use App\Auth\Webauthn\WebauthnService;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

class AuthServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->scoped(WebauthnService::class, function () {

            $attestationStatementSupportManager = AttestationStatementSupportManager::create();
            $attestationStatementSupportManager->add(NoneAttestationStatementSupport::create());


            $factory = new WebauthnSerializerFactory($attestationStatementSupportManager);
            $webauthnSerializer = $factory->create();

            return new WebauthnService($attestationStatementSupportManager, $webauthnSerializer);
        });
    }

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
