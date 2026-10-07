<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use Nelmio\CorsBundle\Options\ProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

// Nelmio merges providers in ascending priority; its path configuration uses -1.
#[AutoconfigureTag('nelmio_cors.options_provider', ['priority' => 0])]
final class AuthorizationCorsOptionsProvider implements ProviderInterface
{
    /** @return array{allow_origin?: list<string>, allow_methods?: list<string>, allow_headers?: list<string>} */
    public function getOptions(Request $request): array
    {
        // Routing decodes paths once and redirects trailing slashes for GET/HEAD.
        // Apply the authorization endpoint's denial before routing or preflight.
        $path = rtrim(rawurldecode($request->getPathInfo()), '/');
        if ($path !== '/api/oauth/authorize') {
            return [];
        }

        return [
            'allow_origin' => [],
            'allow_methods' => [],
            'allow_headers' => [],
        ];
    }
}
