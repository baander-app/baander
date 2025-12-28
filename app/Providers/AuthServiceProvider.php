<?php

namespace App\Providers;

use App\Modules\Auth\OAuth\Guards\OAuthGuard;
use App\Models\User;
use App\Modules\Auth\OAuth\Psr7Factory;
use App\Modules\Auth\Webauthn\CounterChecker;
use App\Modules\Auth\Webauthn\WebauthnService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use League\OAuth2\Server\ResourceServer;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

class AuthServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->scoped(CeremonyStepManagerFactory::class, function () {
            $csm = new CeremonyStepManagerFactory();
            $csm->setCounterChecker(new CounterChecker());
            return $csm;
        });

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
        // Register OAuth guard
        Auth::extend('oauth', function ($app, $name, array $config) {
            return new OAuthGuard(
                $app['request'],
                $app[ResourceServer::class],
                $app[Psr7Factory::class]
            );
        });

        $this->bootGates();
    }

    private function bootGates()
    {
        Gate::define('viewApiDocs', function (User $user) {
            return (bool)$user;
        });

        Gate::define('viewDashboard', function ($user) {
            return $user->isAdmin();
        });

        Gate::define('executeJob', function ($user) {
            return $user->isAdmin();
        });
    }
}
