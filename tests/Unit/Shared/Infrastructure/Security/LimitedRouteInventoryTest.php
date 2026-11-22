<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Security;

use App\Auth\Infrastructure\Security\RateLimitListener;
use App\Catalog\Infrastructure\EventListener\AdminRateLimitListener;
use App\Discovery\Infrastructure\EventListener\DiscoveryRateLimitListener;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\Loader\Psr4DirectoryLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RouteCollection;

/**
 * The auth, discovery and cover-extraction limits charge requests by the name of the route
 * they reach. A renamed route would drop its limit without any other test noticing, so every
 * limited route must exist with the method and path its limit was written for, and every
 * route-name constant (`*_ROUTE`) a limiting listener declares must be listed here.
 *
 * The routes come from `config/routes.yaml` through the routing component's own loaders;
 * compiling the container would exceed the unit suite's memory budget.
 */
final class LimitedRouteInventoryTest extends TestCase
{
    private const array LISTENERS = [
        RateLimitListener::class,
        DiscoveryRateLimitListener::class,
        AdminRateLimitListener::class,
    ];

    /** @var array<string, array{string, string}> route name => [method, path] */
    private const array LIMITED_ROUTES = [
        RateLimitListener::LOGIN_ROUTE => ['POST', '/api/auth/login'],
        RateLimitListener::REGISTER_ROUTE => ['POST', '/api/auth/register'],
        RateLimitListener::PASSWORD_RESET_REQUEST_ROUTE => ['POST', '/api/auth/password/reset-request'],
        RateLimitListener::PASSWORD_RESET_ROUTE => ['POST', '/api/auth/password/reset'],
        RateLimitListener::PASSKEY_OPTIONS_ROUTE => ['POST', '/api/auth/passkey/authenticate/options'],
        RateLimitListener::PASSKEY_AUTHENTICATE_ROUTE => ['POST', '/api/auth/passkey/authenticate'],
        RateLimitListener::PASSKEY_LOGIN_ROUTE => ['POST', '/api/auth/login/passkey'],
        RateLimitListener::EMAIL_VERIFY_ROUTE => ['POST', '/api/auth/email/verify'],
        RateLimitListener::EMAIL_VERIFICATION_RESEND_ROUTE => ['POST', '/api/auth/me/email/verification'],
        RateLimitListener::REFRESH_ROUTE => ['POST', '/api/auth/refresh'],
        RateLimitListener::TOKEN_ROUTE => ['POST', '/api/oauth/token'],
        RateLimitListener::PAR_ROUTE => ['POST', '/api/oauth/par'],
        RateLimitListener::AUTHORIZE_ROUTE => ['GET', '/api/oauth/authorize'],
        RateLimitListener::AUTHORIZE_DECISION_ROUTE => ['POST', '/api/oauth/authorize'],
        RateLimitListener::DEVICE_AUTHORIZE_ROUTE => ['POST', '/api/oauth/device/authorize'],
        RateLimitListener::DEVICE_VERIFY_ROUTE => ['GET', '/api/oauth/device/verify'],
        RateLimitListener::DEVICE_APPROVE_ROUTE => ['POST', '/api/oauth/device/approve'],
        DiscoveryRateLimitListener::REGISTER_ROUTE => ['POST', '/api/discovery/register'],
        DiscoveryRateLimitListener::PAIRING_CODE_ROUTE => ['POST', '/api/discovery/pairing-code'],
        DiscoveryRateLimitListener::COMPLETE_PAIRING_ROUTE => ['POST', '/api/discovery/complete-pairing'],
        AdminRateLimitListener::COVER_EXTRACTION_ROUTE => ['POST', '/api/albums/covers/extract'],
    ];

    private static RouteCollection $routes;

    public static function setUpBeforeClass(): void
    {
        $locator = new FileLocator([dirname(__DIR__, 5) . '/config']);
        $classLoader = new AttributeRouteControllerLoader('prod');
        $loader = new DelegatingLoader(new LoaderResolver([
            new YamlFileLoader($locator, 'prod'),
            $classLoader,
            new AttributeDirectoryLoader($locator, $classLoader),
            new AttributeFileLoader($locator, $classLoader),
            new Psr4DirectoryLoader($locator),
        ]));
        $routes = $loader->load('routes.yaml');
        self::assertInstanceOf(RouteCollection::class, $routes);
        self::$routes = $routes;
    }

    public function testEveryLimitedRouteExistsWithItsMethodAndPath(): void
    {
        self::assertSame([], self::violations(self::$routes));
    }

    public function testTheInventoryListsEveryRouteALimiterNames(): void
    {
        $declared = [];
        foreach (self::LISTENERS as $listener) {
            foreach (new \ReflectionClass($listener)->getReflectionConstants() as $constant) {
                if ($constant->isPublic() && str_ends_with($constant->getName(), '_ROUTE')) {
                    $declared[] = $constant->getValue();
                }
            }
        }

        self::assertEqualsCanonicalizing(array_keys(self::LIMITED_ROUTES), $declared);
    }

    public function testARenamedLimitedRouteFails(): void
    {
        $routes = clone self::$routes;
        $register = $routes->get(RateLimitListener::REGISTER_ROUTE);
        self::assertNotNull($register);
        $routes->remove(RateLimitListener::REGISTER_ROUTE);
        $routes->add('auth_sign_up', $register);

        self::assertSame(['Limited route "auth_register" does not exist.'], self::violations($routes));
    }

    /** @return list<string> */
    private static function violations(RouteCollection $routes): array
    {
        $violations = [];
        foreach (self::LIMITED_ROUTES as $name => [$method, $path]) {
            $route = $routes->get($name);
            if ($route === null) {
                $violations[] = sprintf('Limited route "%s" does not exist.', $name);
            } elseif ($route->getMethods() !== [$method] || $route->getPath() !== $path) {
                $violations[] = sprintf(
                    'Limited route "%s" serves %s %s instead of %s %s.',
                    $name,
                    implode('|', $route->getMethods()),
                    $route->getPath(),
                    $method,
                    $path,
                );
            }
        }

        return $violations;
    }
}
