<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Infrastructure\Security\OAuth\ScopeRouteMap;
use App\Auth\Infrastructure\Security\UnverifiedAccountListener;
use App\Catalog\Interface\Controller\GenreController;
use App\Tests\Support\AdminCliParity\AdminRouteClassifier;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\Loader\Psr4DirectoryLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Every route is public, first-party only, or reachable with named scopes, and a route a
 * delegated token may reach never requires an admin role. A new route fails here until it
 * is classified in ScopeRouteMap, so a delegated token cannot reach it by omission. Every
 * route an unverified account may reach exists and is listed once.
 *
 * The routes come from `config/routes.yaml` through the routing component's own loaders;
 * compiling the container would exceed the unit suite's memory budget.
 */
final class ScopeRouteInventoryTest extends TestCase
{
    private const array KNOWN_SCOPES = ['profile', 'email', 'library', 'playlist'];

    private static RouteCollection $routes;
    private static AdminRouteClassifier $classifier;

    public static function setUpBeforeClass(): void
    {
        $projectDir = dirname(__DIR__, 6);
        $locator = new FileLocator([$projectDir . '/config']);
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
        self::$classifier = AdminRouteClassifier::fromSecurityConfig($projectDir . '/config/packages/security.yaml');
    }

    public function testEveryRouteIsClassifiedOnceAndNoDelegatedRouteRequiresAnAdmin(): void
    {
        self::assertSame([], self::violations(
            self::$routes,
            ScopeRouteMap::PUBLIC_ROUTES,
            ScopeRouteMap::FIRST_PARTY_ROUTES,
            ScopeRouteMap::SCOPED_ROUTES,
        ));
    }

    public function testTheInventoryCoversTheApi(): void
    {
        $api = array_filter(self::$routes->all(), static fn (Route $route): bool => str_starts_with($route->getPath(), '/api/'));

        self::assertGreaterThan(300, count($api), 'The router lost most API routes; the loader no longer reads the controllers.');
    }

    public function testANewRouteFailsUntilItIsClassified(): void
    {
        $routes = clone self::$routes;
        $routes->add('album_relabel', new Route('/api/albums/{publicId}/relabel', methods: ['POST']));

        self::assertSame(
            ['Route "album_relabel" is not classified.'],
            self::violations($routes, ScopeRouteMap::PUBLIC_ROUTES, ScopeRouteMap::FIRST_PARTY_ROUTES, ScopeRouteMap::SCOPED_ROUTES),
        );
    }

    public function testAnAdminRouteMappedToAScopeFails(): void
    {
        $firstParty = array_values(array_diff(ScopeRouteMap::FIRST_PARTY_ROUTES, ['genre_store', 'admin_users_list']));
        $scoped = [...ScopeRouteMap::SCOPED_ROUTES, 'genre_store' => ['library'], 'admin_users_list' => ['library']];

        self::assertSame([
            'Route "admin_users_list" requires an admin role and must be first-party only.',
            'Route "genre_store" requires an admin role and must be first-party only.',
        ], self::violations(self::$routes, ScopeRouteMap::PUBLIC_ROUTES, $firstParty, $scoped));
        self::assertSame(GenreController::class . '::store', self::$routes->get('genre_store')?->getDefault('_controller'));
    }

    public function testAStaleOrDoubleEntryOrAnUnknownScopeFails(): void
    {
        $scoped = [...ScopeRouteMap::SCOPED_ROUTES, 'album_relabel' => ['library'], 'auth_me' => ['profile', 'admin']];

        self::assertSame([
            'Route "auth_me" names unknown scopes: admin.',
            'Route "auth_login_login" is classified more than once.',
            'Classified route "album_relabel" does not exist.',
        ], self::violations(self::$routes, [...ScopeRouteMap::PUBLIC_ROUTES], [...ScopeRouteMap::FIRST_PARTY_ROUTES, 'auth_login_login'], $scoped));
    }

    public function testEveryRouteAnUnverifiedAccountReachesExistsOnce(): void
    {
        self::assertSame([], self::unverifiedRouteViolations(self::$routes, UnverifiedAccountListener::ROUTES));
    }

    public function testARemovedOrRepeatedRouteOfAnUnverifiedAccountFails(): void
    {
        $routes = clone self::$routes;
        $routes->remove('auth_me_email');

        self::assertSame([
            'Route "auth_refresh" is listed more than once for unverified accounts.',
            'Route "auth_me_email" for unverified accounts does not exist.',
        ], self::unverifiedRouteViolations($routes, [...UnverifiedAccountListener::ROUTES, 'auth_refresh']));
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private static function unverifiedRouteViolations(RouteCollection $routes, array $allowed): array
    {
        $violations = [];
        foreach (array_keys(array_filter(array_count_values($allowed), static fn (int $count): bool => $count > 1)) as $name) {
            $violations[] = sprintf('Route "%s" is listed more than once for unverified accounts.', $name);
        }
        foreach (array_diff($allowed, array_keys($routes->all())) as $name) {
            $violations[] = sprintf('Route "%s" for unverified accounts does not exist.', $name);
        }

        return $violations;
    }

    /**
     * @param list<string> $public
     * @param list<string> $firstParty
     * @param array<string, list<string>> $scoped
     *
     * @return list<string>
     */
    private static function violations(RouteCollection $routes, array $public, array $firstParty, array $scoped): array
    {
        $violations = [];
        foreach ($scoped as $name => $scopes) {
            $unknown = array_diff($scopes, self::KNOWN_SCOPES);
            if ($scopes === [] || $unknown !== []) {
                $violations[] = sprintf('Route "%s" names unknown scopes: %s.', $name, implode(', ', $unknown));
            }
        }

        $classified = [...$public, ...$firstParty, ...array_keys($scoped)];
        foreach (array_keys(array_filter(array_count_values($classified), static fn (int $count): bool => $count > 1)) as $name) {
            $violations[] = sprintf('Route "%s" is classified more than once.', $name);
        }
        foreach (array_diff($classified, array_keys($routes->all())) as $name) {
            $violations[] = sprintf('Classified route "%s" does not exist.', $name);
        }

        foreach ($routes->all() as $name => $route) {
            if (!in_array($name, $classified, true)) {
                $violations[] = sprintf('Route "%s" is not classified.', $name);
            } elseif (!in_array($name, $firstParty, true) && self::$classifier->isAdminGuarded($route)) {
                $violations[] = sprintf('Route "%s" requires an admin role and must be first-party only.', $name);
            }
        }

        return $violations;
    }
}
