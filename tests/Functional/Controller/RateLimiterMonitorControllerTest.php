<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Application\Exception\RateLimiterClearFailedException;
use App\Shared\Application\Port\RateLimiterAdministrationInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Tests\Functional\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Yaml\Yaml;

final class RateLimiterMonitorControllerTest extends TestCase
{
    public function testListShowsEveryConfiguredLimiterWithItsEffectiveConfiguration(): void
    {
        $response = $this->authenticatedRequest('GET', '/api/monitor/rate-limiters', $this->createAdminUser());

        $data = $this->assertJsonResponse($response, 200)['data'];
        $limiters = $data['limiters'];
        $configured = $this->configuredLimiterNames();

        self::assertEqualsCanonicalizing($configured, array_keys($limiters));
        self::assertSame(count($configured), $data['count']);
        // The test environment overrides every limit to 10000, so this proves the
        // list reflects the effective container configuration rather than a copy.
        self::assertSame(10000, $limiters['anonymous_api']['limit']);
        self::assertSame('fixed_window', $limiters['config_check']['policy']);
        self::assertSame('60 seconds', $limiters['discovery_endpoint_ip']['interval']);

        $pools = array_column($limiters, 'storageNamespace');
        self::assertCount(count($configured), array_unique($pools), 'Each limiter must have its own storage namespace.');
        foreach ($limiters as $name => $limiter) {
            self::assertNotEmpty($limiter['description'], sprintf('Limiter "%s" has no description.', $name));
        }
    }

    public function testClearResetsOnlyTheNamedLimiter(): void
    {
        $key = 'u27-' . bin2hex(random_bytes(4));
        $this->exhaust('batch_cover_extract', $key);
        $this->exhaust('config_check', $key);

        $response = $this->authenticatedRequest(
            'DELETE',
            '/api/monitor/rate-limiters/batch_cover_extract/clear?confirm=true',
            $this->createAdminUser(),
        );

        $data = $this->assertJsonResponse($response, 200)['data'];
        self::assertTrue($data['cleared']);
        self::assertSame('batch_cover_extract', $data['limiter']);
        self::assertTrue($this->limiter('batch_cover_extract')->create($key)->consume()->isAccepted());
        self::assertFalse($this->limiter('config_check')->create($key)->consume()->isAccepted());
    }

    public function testClearAllResetsEveryLimiter(): void
    {
        $key = 'u27-' . bin2hex(random_bytes(4));
        $this->exhaust('config_check', $key);
        $this->exhaust('discovery_endpoint_ip', $key);

        $response = $this->authenticatedRequest(
            'DELETE',
            '/api/monitor/rate-limiters/clear?confirm=true',
            $this->createAdminUser(),
        );

        $data = $this->assertJsonResponse($response, 200)['data'];
        self::assertTrue($data['cleared']);
        self::assertEqualsCanonicalizing($this->configuredLimiterNames(), $data['limiters']);
        self::assertTrue($this->limiter('config_check')->create($key)->consume()->isAccepted());
        self::assertTrue($this->limiter('discovery_endpoint_ip')->create($key)->consume()->isAccepted());
    }

    public function testClearUnknownLimiterReturnsNotFound(): void
    {
        $response = $this->authenticatedRequest(
            'DELETE',
            '/api/monitor/rate-limiters/no_such_limiter/clear?confirm=true',
            $this->createAdminUser(),
        );

        $this->assertJsonResponse($response, 404);
    }

    #[DataProvider('failedClearRoutes')]
    public function testFailedClearReturnsAnUncacheableServiceFailure(string $route, string $method): void
    {
        $administration = $this->createMock(RateLimiterAdministrationInterface::class);
        $administration->expects(self::once())->method($method)
            ->willThrowException(RateLimiterClearFailedException::forLimiters(['config_check']));
        static::getContainer()->set(RateLimiterAdministrationInterface::class, $administration);

        $response = $this->authenticatedRequest('DELETE', $route . '?confirm=true', $this->createAdminUser());
        $this->assertJsonResponse($response, 503);
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function failedClearRoutes(): iterable
    {
        yield 'named clear' => ['/api/monitor/rate-limiters/config_check/clear', 'clear'];
        yield 'clear all' => ['/api/monitor/rate-limiters/clear', 'clearAll'];
    }

    private function exhaust(string $name, string $key): void
    {
        $limiter = $this->limiter($name)->create($key);
        $limiter->consume(10000);

        self::assertFalse($limiter->consume()->isAccepted(), sprintf('Limiter "%s" was not exhausted.', $name));
    }

    private function limiter(string $name): RateLimiterFactoryInterface
    {
        $factory = static::getContainer()->get('limiter.' . $name);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);

        return $factory;
    }

    /** @return list<string> */
    private function configuredLimiterNames(): array
    {
        $config = Yaml::parseFile(dirname(__DIR__, 3) . '/config/packages/framework.yaml');

        return array_keys($config['framework']['rate_limiter']);
    }
}
