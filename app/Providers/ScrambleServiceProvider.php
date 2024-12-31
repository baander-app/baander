<?php

namespace App\Providers;

use App\Packages\Humanize\HumanDuration;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;

class ScrambleServiceProvider extends ServiceProvider
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
        $humanDuration = new HumanDuration();

        $accessTokenLifeTime = $humanDuration->humanize(config('sanctum.access_token_expiration') * 60);
        $refreshTokenLifeTime = $humanDuration->humanize(config('sanctum.refresh_token_expiration') * 60);
        $streamTokenLifeTime = $humanDuration->humanize(config('sanctum.stream_token_expiration') * 60);

        $desc = <<<DESC
### Access token
Only tokens with the ability 'access-api' will have access to the endpoints. Access tokens have a lifetime of $accessTokenLifeTime.

### Stream token
Stream tokens can only be used for accessing media streams. Stream tokens have a lifetime of $streamTokenLifeTime.

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
    }
}
