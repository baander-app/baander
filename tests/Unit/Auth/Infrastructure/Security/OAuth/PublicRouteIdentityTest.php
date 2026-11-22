<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Auth\Infrastructure\Security\OAuth\ScopeRouteMap;
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
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A public route must not read who is calling. The scope listener lets any delegated token reach a
 * public route, so a public controller that falls back to the authenticated user hands that user's
 * data to a token whose scopes never covered it. Such a route belongs in the scoped or first-party
 * lists instead.
 *
 * This is a deny-list heuristic, not a proof: it reads the controller method and the same-class
 * private and protected methods it calls, and fails on the identity reads this codebase uses
 * (current-user parameters, user and token getters, and the current read scope).
 */
final class PublicRouteIdentityTest extends TestCase
{
    /** Calls that read the authenticated caller or a scope derived from it. */
    private const array IDENTITY_READS = ['->getUser(', '->getCurrentUser(', '->getCurrentSecurityUser(', '->getActorId(', '->current(', '->getToken('];

    /** Parameter types the argument resolver fills with the authenticated caller. */
    private const array IDENTITY_TYPES = [AuthenticatedUserIdentityInterface::class, UserInterface::class];

    private static RouteCollection $routes;

    public static function setUpBeforeClass(): void
    {
        $locator = new FileLocator([dirname(__DIR__, 6) . '/config']);
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

    public function testNoPublicApiRouteReadsTheCallersIdentity(): void
    {
        self::assertSame([], self::violations(self::$routes, ScopeRouteMap::PUBLIC_ROUTES));
    }

    public function testTheCheckReachesEveryPublicApiRoute(): void
    {
        $checked = array_filter(
            ScopeRouteMap::PUBLIC_ROUTES,
            static fn (string $name): bool => str_starts_with(self::$routes->get($name)?->getPath() ?? '', '/api/'),
        );

        self::assertGreaterThan(20, count($checked), 'The check no longer finds the public API routes.');
    }

    public function testARouteWhosePrivateHelperReadsTheCallerFails(): void
    {
        self::assertSame(
            ['Public route "fixture_helper" reads the caller in ' . IdentityReadingFixture::class . '::viaHelper.'],
            self::violations(self::fixtureRoutes(['fixture_helper' => 'viaHelper']), ['fixture_helper']),
        );
    }

    public function testARouteThatTakesTheCurrentUserFails(): void
    {
        self::assertSame(
            ['Public route "fixture_parameter" reads the caller in ' . IdentityReadingFixture::class . '::viaParameter.'],
            self::violations(self::fixtureRoutes(['fixture_parameter' => 'viaParameter']), ['fixture_parameter']),
        );
    }

    public function testARouteThatReadsNoIdentityPasses(): void
    {
        self::assertSame([], self::violations(self::fixtureRoutes(['fixture_anonymous' => 'anonymous']), ['fixture_anonymous']));
    }

    /** @param array<string, string> $methods route name => fixture method */
    private static function fixtureRoutes(array $methods): RouteCollection
    {
        $routes = new RouteCollection();
        foreach ($methods as $name => $method) {
            $routes->add($name, new Route('/api/fixture/' . $name, ['_controller' => IdentityReadingFixture::class . '::' . $method]));
        }

        return $routes;
    }

    /**
     * @param list<string> $publicRoutes
     * @return list<string>
     */
    private static function violations(RouteCollection $routes, array $publicRoutes): array
    {
        $violations = [];
        foreach ($publicRoutes as $name) {
            $route = $routes->get($name);
            $controller = $route?->getDefault('_controller');
            if ($route === null || !str_starts_with($route->getPath(), '/api/') || !is_string($controller) || !str_contains($controller, '::')) {
                continue;
            }
            $method = \ReflectionMethod::createFromMethodName($controller);
            if (self::readsIdentity($method, [])) {
                $violations[] = sprintf('Public route "%s" reads the caller in %s.', $name, $controller);
            }
        }

        return $violations;
    }

    /** @param list<string> $visited */
    private static function readsIdentity(\ReflectionMethod $method, array $visited): bool
    {
        if (array_any($method->getParameters(), self::isIdentityParameter(...))) {
            return true;
        }
        $body = self::body($method);
        if (array_any(self::IDENTITY_READS, static fn (string $read): bool => str_contains($body, $read))) {
            return true;
        }

        $class = $method->getDeclaringClass();
        $visited[] = $method->getName();
        preg_match_all('/(?:\$this->|self::|static::)(\w+)\(/', $body, $calls);

        return array_any(
            array_unique($calls[1]),
            static fn (string $callee): bool => !in_array($callee, $visited, true)
                && $class->hasMethod($callee)
                && !$class->getMethod($callee)->isPublic()
                && $class->getMethod($callee)->getDeclaringClass()->getName() === $class->getName()
                && self::readsIdentity($class->getMethod($callee), $visited),
        );
    }

    private static function isIdentityParameter(\ReflectionParameter $parameter): bool
    {
        if ($parameter->getAttributes(CurrentUser::class) !== []) {
            return true;
        }
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType
            && array_any(self::IDENTITY_TYPES, static fn (string $identity): bool => is_a($type->getName(), $identity, true));
    }

    private static function body(\ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        if ($file === false) {
            return '';
        }
        $lines = file($file) ?: [];

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}

/** Controller shapes the identity check must catch, and one it must accept. */
final class IdentityReadingFixture
{
    public function __construct(private readonly \stdClass $security)
    {
    }

    public function viaHelper(): string
    {
        return $this->owner();
    }

    public function viaParameter(#[CurrentUser] AuthenticatedUserIdentityInterface $user): string
    {
        return $user::class;
    }

    public function anonymous(string $publicId): string
    {
        return strtoupper($publicId);
    }

    private function owner(): string
    {
        return (string) $this->security->getUser();
    }
}
