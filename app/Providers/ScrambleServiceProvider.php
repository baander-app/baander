<?php

namespace App\Providers;

use App\Format\Duration;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ScrambleServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $humanDuration = new Duration();

        $accessTokenLifeTime = $humanDuration->humanize(config('oauth.access_token_ttl'));
        $refreshTokenLifeTime = $humanDuration->humanize(config('oauth.refresh_token_ttl'));

        $desc = <<<DESC
### Access token
Only tokens with the ability 'access-api' will have access to the endpoints. Access tokens have a lifetime of $accessTokenLifeTime.

### Refresh token
The refresh token has the 'issue-access-token' ability. Refresh tokens have a lifetime of $refreshTokenLifeTime.
It can be used to refresh access and stream tokens.

#### Tip

Tokens can be used as a query parameter in cases where its not possible to add a header (e.g. mp3 streaming in the browser). Append the query parameter `_token=YOUR_TOKEN`.
DESC;

        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) use ($desc) {
            $openApi->secure(
                (SecurityScheme::http('bearer'))
                    ->setDescription($desc),
            );
        });

        Scramble::routes(function (Route $route) {
            $whitelist = ['api/', 'webauthn'];
            $blacklist = [];

            // Exclude OAuth and Sanctum routes from documentation
            if (array_any($blacklist, fn($str) => Str::contains($route->uri, $str))) {
                return false;
            }

            // Include only whitelisted routes
            return array_any($whitelist, fn($str) => Str::contains($route->uri, $str));
        });

        Scramble::ignoreDefaultRoutes();
        Scramble::registerJsonSpecificationRoute('/docs/api.json');
    }
}
