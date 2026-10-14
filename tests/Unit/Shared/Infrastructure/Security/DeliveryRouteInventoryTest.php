<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Security;

use App\Auth\Infrastructure\Security\ApiRateLimitListener;
use App\Auth\Infrastructure\Security\OAuth\DpopBindingListener;
use App\Tests\Fixtures\Shared\SignedDeliveryRoutes;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\Loader\Psr4DirectoryLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Yaml;

/**
 * The signed delivery routes are listed three times: the firewall rows that grant them
 * public access, the DPoP binding listener's exemption and the rate limiter's exemption.
 * This test derives the set from the application's routes and the firewall rows and holds
 * the two exemptions and the test fixture to it, so none of the copies drifts.
 *
 * The routes come from `config/routes.yaml` through the routing component's own loaders;
 * compiling the container would exceed the unit suite's memory budget.
 */
final class DeliveryRouteInventoryTest extends TestCase
{
    /** Every signed delivery route lives under the transcode prefix. */
    private const string DELIVERY_PREFIX = '/api/transcode/';
    private const string VIDEO_ID = '0192f6c8-5b4e-7c3a-9d2e-1f0a8b7c6d5e';
    private const string JOB_ID = 'V1StGXR8_Z5jdHi6B-myT';

    private static RouteCollection $routes;

    public static function setUpBeforeClass(): void
    {
        $locator = new FileLocator([self::projectDir() . '/config']);
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

    public function testPublicDeliveryRoutesAreExactlyTheProofExemptionAndWithinTheRateLimitExemption(): void
    {
        $inventory = $this->publicDeliveryRoutes();

        self::assertNotSame([], $inventory);
        self::assertEqualsCanonicalizing($inventory, DpopBindingListener::SIGNED_DELIVERY_ROUTES);
        self::assertSame([], array_values(array_diff($inventory, ApiRateLimitListener::EXEMPT_ROUTES)));
    }

    public function testFixturePathsResolveToEveryPublicDeliveryRoute(): void
    {
        $paths = SignedDeliveryRoutes::paths(self::VIDEO_ID, self::JOB_ID);
        $matcher = new UrlMatcher(self::$routes, new RequestContext());

        self::assertEqualsCanonicalizing($this->publicDeliveryRoutes(), array_keys($paths));
        foreach ($paths as $name => $path) {
            $pathInfo = parse_url($path, PHP_URL_PATH);
            self::assertIsString($pathInfo);
            self::assertSame($name, $matcher->match($pathInfo)['_route'], $path);
        }
    }

    /**
     * Route names under the delivery prefix whose first matching firewall row grants a GET
     * request public access. Symfony applies only the first matching row.
     *
     * @return list<string>
     */
    private function publicDeliveryRoutes(): array
    {
        /** @var array{security: array{access_control: list<array{path: string, methods?: list<string>|string, roles?: list<string>|string}>}} $config */
        $config = Yaml::parseFile(self::projectDir() . '/config/packages/security.yaml');
        $rules = $config['security']['access_control'];

        $names = [];
        foreach (self::$routes as $name => $route) {
            if (!str_starts_with($route->getPath(), self::DELIVERY_PREFIX)) {
                continue;
            }
            if ($route->getMethods() !== [] && !in_array('GET', $route->getMethods(), true)) {
                continue;
            }
            foreach ($rules as $rule) {
                $methods = array_map(strtoupper(...), (array) ($rule['methods'] ?? []));
                if ($methods !== [] && !in_array('GET', $methods, true)) {
                    continue;
                }
                if (preg_match('{' . $rule['path'] . '}', $route->getPath()) !== 1) {
                    continue;
                }
                if (in_array('PUBLIC_ACCESS', (array) ($rule['roles'] ?? []), true)) {
                    $names[] = $name;
                }
                break;
            }
        }

        return $names;
    }

    private static function projectDir(): string
    {
        return dirname(__DIR__, 5);
    }
}
