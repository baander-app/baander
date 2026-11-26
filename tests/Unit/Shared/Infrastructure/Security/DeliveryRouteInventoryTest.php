<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Security;

use App\Auth\Infrastructure\Security\ApiRateLimitListener;
use App\Auth\Infrastructure\Security\OAuth\DpopBindingListener;
use App\Tests\Fixtures\Shared\SignedDeliveryRoutes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\Loader\Psr4DirectoryLoader;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Yaml;

/**
 * The adaptive signed delivery routes are listed three times: the firewall rows that grant them
 * public access, the DPoP binding listener's exemption and the rate limiter's exemption.
 * This test derives the set from the application's routes and the firewall rows and holds
 * the two exemptions and the test fixture to it, so none of the copies drifts. In the other
 * direction it holds the rows to the paths the delivery routes serve: a path no delivery
 * route serves is not public.
 *
 * The routes come from `config/routes.yaml` through the routing component's own loaders;
 * compiling the container would exceed the unit suite's memory budget.
 */
final class DeliveryRouteInventoryTest extends TestCase
{
    /** Adaptive delivery uses signatures instead of authenticated DPoP requests. */
    private const string DELIVERY_PREFIX = '/api/transcode/';
    private const string VIDEO_ID = 'Hk4oZ2pQ9xLm0vB7tR3sW';
    private const string JOB_ID = 'V1StGXR8_Z5jdHi6B-myT';
    /** Values a delivery route's placeholders take in the URLs the application signs. */
    private const array SAMPLE_PARAMETERS = [
        '{videoId}' => self::VIDEO_ID,
        '{jobPublicId}' => self::JOB_ID,
        '{track}' => 'video',
        '{generation}' => '3',
        '{language}' => 's2',
        '{segment}' => 'full',
    ];

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
        $rateLimited = array_diff($inventory, ApiRateLimitListener::EXEMPT_ROUTES);
        self::assertSame([], array_values($rateLimited));
    }

    public function testFixturePathsResolveToEveryPublicDeliveryRoute(): void
    {
        $matcher = new UrlMatcher(self::$routes, new RequestContext());
        foreach (['video', 'audio'] as $track) {
            $paths = SignedDeliveryRoutes::paths(self::VIDEO_ID, self::JOB_ID, track: $track);
            self::assertEqualsCanonicalizing($this->publicDeliveryRoutes(), array_keys($paths));
            foreach ($paths as $name => $path) {
                $pathInfo = parse_url($path, PHP_URL_PATH);
                self::assertIsString($pathInfo);
                self::assertSame($name, $matcher->match($pathInfo)['_route'], $path);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function pathsNoDeliveryRouteServes(): iterable
    {
        yield 'the job routes in place of a video' => ['/api/transcode/jobs/master.m3u8'];
        yield 'the session routes in place of a video' => ['/api/transcode/sessions/manifest.mpd'];
        yield 'the session routes in place of a job' => ['/api/transcode/sessions/tracks/video/init.mp4'];
        yield 'the artifact prefix in place of a video' => ['/api/transcode/artifacts/master.m3u8'];
        yield 'a job ID that is not a public ID' => ['/api/transcode/job/tracks/audio/segment.m4s'];
        yield 'an artifact job ID that is not a public ID' => ['/api/transcode/artifacts/job/3/tracks/video/init.mp4'];
        yield 'a language in place of a subtitle key' => ['/api/transcode/' . self::JOB_ID . '/subtitles/en/media.m3u8'];
        yield 'a numbered subtitle segment' => ['/api/transcode/' . self::JOB_ID . '/subtitles/s2/0.vtt'];
        yield 'a dotted subtitle segment' => ['/api/transcode/' . self::JOB_ID . '/subtitles/s2/a.b.vtt'];
    }

    #[DataProvider('pathsNoDeliveryRouteServes')]
    public function testPathsNoDeliveryRouteServesAreNotPublic(string $path): void
    {
        self::assertFalse($this->isPublic($path), $path);
        $matcher = new UrlMatcher(self::$routes, new RequestContext());
        try {
            $route = $matcher->match($path)['_route'];
        } catch (ResourceNotFoundException) {
            $route = null;
        }
        self::assertNotContains($route, $this->publicDeliveryRoutes(), $path);
    }

    public function testOriginalMediaIsExemptFromGenericLimitsButRetainsAuthenticatedProofChecks(): void
    {
        $inventory = $this->publicDeliveryRoutes('/api/stream/');

        self::assertEqualsCanonicalizing(['stream_original_video', 'stream_original_track'], $inventory);
        self::assertEqualsCanonicalizing($inventory, $this->publicDeliveryRoutes('/api/stream/', 'HEAD'));
        $rateLimited = array_diff($inventory, ApiRateLimitListener::EXEMPT_ROUTES);
        self::assertSame([], array_values($rateLimited));
        $dpopSigned = array_intersect($inventory, DpopBindingListener::SIGNED_DELIVERY_ROUTES);
        self::assertSame([], array_values($dpopSigned));
    }

    /**
     * Route names under the delivery prefix whose path, with sample parameters, the firewall
     * grants public access.
     *
     * @return list<string>
     */
    private function publicDeliveryRoutes(string $prefix = self::DELIVERY_PREFIX, string $method = 'GET'): array
    {
        $names = [];
        foreach (self::$routes as $name => $route) {
            if (!str_starts_with($route->getPath(), $prefix)) {
                continue;
            }
            if ($route->getMethods() !== [] && !in_array($method, $route->getMethods(), true)) {
                continue;
            }
            if ($this->isPublic(strtr($route->getPath(), self::SAMPLE_PARAMETERS), $method)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** Whether the first firewall row matching the request grants it public access; Symfony applies only that row. */
    private function isPublic(string $path, string $method = 'GET'): bool
    {
        /** @var array{security: array{access_control: list<array{path: string, methods?: list<string>|string, roles?: list<string>|string}>}} $config */
        $config = Yaml::parseFile(self::projectDir() . '/config/packages/security.yaml');
        foreach ($config['security']['access_control'] as $rule) {
            $methods = array_map(strtoupper(...), (array) ($rule['methods'] ?? []));
            if ($methods !== [] && !in_array($method, $methods, true)) {
                continue;
            }
            if (preg_match('{' . $rule['path'] . '}', $path) === 1) {
                return in_array('PUBLIC_ACCESS', (array) ($rule['roles'] ?? []), true);
            }
        }

        return false;
    }

    private static function projectDir(): string
    {
        return dirname(__DIR__, 5);
    }
}
