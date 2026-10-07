<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

/**
 * The analytics `from` is inclusive and `to` exclusive; both are RFC 3339 instants with a timezone.
 */
final class JobAnalyticsControllerTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdminUser();
        foreach (['2026-03-15 09:59:59.999999+00', '2026-03-15 10:00:00+00', '2026-03-15 10:59:59.999999+00', '2026-03-15 11:00:00+00'] as $index => $createdAt) {
            $this->entityManager->getConnection()->executeStatement(
                "INSERT INTO job_monitors (id, job_id, name, status, created_at, updated_at)
                 VALUES (gen_random_uuid(), :job_id, 'AnalyticsProbe', 'queued', :created_at, :created_at)",
                ['job_id' => 'analytics-http-' . $index, 'created_at' => $createdAt],
            );
        }
    }

    public function testTheSummaryCountsJobsFromTheStartUpToButNotIncludingTheEnd(): void
    {
        foreach (['from=2026-03-15T10:00:00Z&to=2026-03-15T11:00:00Z', 'from=2026-03-15T12:00:00%2B02:00&to=2026-03-15T13:00:00.000000%2B02:00'] as $query) {
            $summary = $this->assertJsonResponse($this->summary($query), 200, 'data')['data'];

            self::assertSame(2, $summary['statusCounts']['queued'], $query);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidRanges(): iterable
    {
        yield 'malformed from' => ['from=yesterday&to=2026-03-15T11:00:00Z', 'from'];
        yield 'from without a timezone' => ['from=2026-03-15T10:00:00&to=2026-03-15T11:00:00Z', 'from'];
        yield 'malformed to' => ['from=2026-03-15T10:00:00Z&to=2026-02-30T11:00:00Z', 'to'];
        yield 'empty range' => ['from=2026-03-15T10:00:00Z&to=2026-03-15T12:00:00%2B02:00', 'to'];
        yield 'reversed range' => ['from=2026-03-15T11:00:00Z&to=2026-03-15T10:00:00Z', 'to'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRanges')]
    public function testAnInvalidRangeIsABadRequest(string $query, string $parameter): void
    {
        foreach (['summary', 'timing', 'failures'] as $endpoint) {
            $error = $this->assertJsonResponse(
                $this->authenticatedRequest('GET', '/api/monitor/analytics/' . $endpoint . '?' . $query, $this->admin),
                400,
            );

            self::assertSame([$parameter], array_keys($error['error']['details']), $endpoint);
        }
    }

    private function summary(string $query): \Symfony\Component\HttpFoundation\Response
    {
        return $this->authenticatedRequest('GET', '/api/monitor/analytics/summary?' . $query, $this->admin);
    }
}
