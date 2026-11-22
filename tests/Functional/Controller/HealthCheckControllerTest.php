<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The unauthenticated infrastructure probes Docker and Kubernetes use show whether Baander is
 * up, and nothing about how it is built or loaded; administrators read the full report.
 *
 *   GET /health            component-level health (PostgreSQL, Redis, Swoole, memory)
 *   GET /ready             readiness probe (dependency availability)
 *   GET /live              liveness probe (always 200 if the process can respond)
 *   GET /api/debug/health  the full report, for administrators
 */
final class HealthCheckControllerTest extends TestCase
{
    public function testHealthShowsEachComponentsStatusAndNoDetail(): void
    {
        $response = $this->anonymousRequest('GET', '/health');

        self::assertContains($response->getStatusCode(), [200, 503]);
        $body = self::body($response);
        self::assertSame(['status', 'checks'], array_keys($body));
        self::assertContains($body['status'], ['healthy', 'unhealthy']);
        self::assertStatusOnly($body['checks']);
        self::assertContains('redis', array_column($body['checks'], 'component'));
        self::assertContains('memory', array_column($body['checks'], 'component'));
    }

    public function testReadyShowsStatusAndWhetherTheServerDrains(): void
    {
        $response = $this->anonymousRequest('GET', '/ready');

        self::assertContains($response->getStatusCode(), [200, 503]);
        $body = self::body($response);
        self::assertSame(['status', 'draining', 'checks'], array_keys($body));
        self::assertContains($body['status'], ['ready', 'not_ready']);
        self::assertFalse($body['draining'], 'Outside the web server nothing is draining.');
        self::assertStatusOnly($body['checks']);
    }

    public function testLiveAlwaysReturns200AndShowsStatusOnly(): void
    {
        $response = $this->anonymousRequest('GET', '/live');

        self::assertSame(200, $response->getStatusCode());
        $body = self::body($response);
        self::assertSame(['status', 'draining', 'checks'], array_keys($body));
        self::assertSame('alive', $body['status']);
        self::assertFalse($body['draining'], 'Outside the web server nothing is draining.');
        self::assertStatusOnly($body['checks']);
    }

    public function testAnAdministratorReadsTheFullReport(): void
    {
        $response = $this->authenticatedRequest('GET', '/api/debug/health', $this->createAdminUser());

        self::assertContains($response->getStatusCode(), [200, 503], (string) $response->getContent());
        $body = self::body($response);
        self::assertContains($body['status'], ['healthy', 'unhealthy']);
        $checks = array_column($body['checks'], null, 'component');
        self::assertIsInt($checks['redis']['details']['dbSize']);
        self::assertArrayHasKey('responseTimeMs', $checks['postgresql']);
        self::assertArrayHasKey('usageMb', $checks['memory']['details']);
        self::assertArrayHasKey('limitMb', $checks['memory']['details']);
        self::assertArrayHasKey('webProfile', $checks['worker_profile']['details']);
    }

    public function testAMemberIsRefusedTheFullReport(): void
    {
        $response = $this->authenticatedRequest('GET', '/api/debug/health', $this->createTestUser(verified: true));

        self::assertSame(403, $response->getStatusCode());
        $body = self::body($response);
        self::assertSame(403, $body['error']['code']);
        self::assertArrayNotHasKey('checks', $body);
    }

    public function testTheFullReportNeedsASignedInCaller(): void
    {
        self::assertSame(401, $this->anonymousRequest('GET', '/api/debug/health')->getStatusCode());
    }

    /** @return array<string, mixed> */
    private static function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string, mixed>> $checks */
    private static function assertStatusOnly(array $checks): void
    {
        self::assertNotEmpty($checks);
        foreach ($checks as $check) {
            self::assertSame(['component', 'status'], array_keys($check), json_encode($check, JSON_THROW_ON_ERROR));
        }
    }
}
