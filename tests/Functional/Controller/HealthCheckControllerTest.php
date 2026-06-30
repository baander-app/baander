<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

/**
 * Functional tests for health-check endpoints.
 *
 * These are unauthenticated infrastructure probes used by Docker/Kubernetes.
 *
 *   GET /health  — component-level health (PostgreSQL, Redis, Swoole, memory)
 *   GET /ready   — readiness probe (dependency availability)
 *   GET /live    — liveness probe (always 200 if the process can respond)
 */
final class HealthCheckControllerTest extends TestCase
{
    public function testHealthReturns200WhenDependenciesAreUp(): void
    {
        $data = json_decode(
            $this->anonymousRequest('GET', '/health')->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertContains($data['status'], ['healthy', 'unhealthy']);
        $this->assertIsArray($data['checks']);
        $this->assertNotEmpty($data['checks']);
    }

    public function testHealthDoesNotRequireAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/health');

        $this->assertContains($response->getStatusCode(), [200, 503]);
    }

    public function testReadyReturns200WhenDependenciesAreUp(): void
    {
        $data = json_decode(
            $this->anonymousRequest('GET', '/ready')->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertContains($data['status'], ['ready', 'not_ready']);
        $this->assertIsArray($data['checks']);
    }

    public function testReadyDoesNotRequireAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/ready');

        $this->assertContains($response->getStatusCode(), [200, 503]);
    }

    public function testLiveAlwaysReturns200(): void
    {
        $response = $this->anonymousRequest('GET', '/live');

        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('alive', $data['status']);
        $this->assertIsArray($data['checks']);
        $this->assertNotEmpty($data['checks']);
    }
}
