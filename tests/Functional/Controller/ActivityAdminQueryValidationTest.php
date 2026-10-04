<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ActivityAdminQueryValidationTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function invalidQueries(): iterable
    {
        foreach (['summary', 'top-tracks', 'top-artists', 'engagement'] as $endpoint) {
            foreach (['from=not-a-date', 'from=2026-02-31', 'from=tomorrow', 'from[]=', 'from=', 'to=not-a-date', 'from=2026-04-02&to=2026-04-01'] as $query) {
                yield $endpoint . '?' . $query => [$endpoint, $query];
            }
        }
        foreach (['top-tracks', 'top-artists'] as $endpoint) {
            foreach (['limit=0', 'limit=101', 'limit=1.5', 'limit=abc', 'limit[]=1'] as $query) {
                yield $endpoint . '?' . $query => [$endpoint, $query];
            }
        }
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesReturnApiErrors(string $endpoint, string $query): void
    {
        $response = $this->authenticatedRequest('GET', '/api/admin/activity/' . $endpoint . '?' . $query, $this->createAdminUser());

        $data = $this->assertJsonResponse($response, 400);
        self::assertArrayHasKey('error', $data);
        self::assertSame(400, $data['error']['code']);
    }

    public function testValidLeapDayRangeIsAccepted(): void
    {
        $response = $this->authenticatedRequest('GET', '/api/admin/activity/summary?from=2024-02-29&to=2024-02-29', $this->createAdminUser());

        $data = $this->assertJsonResponse($response, 200);
        self::assertSame(0, $data['data']['total_plays']);
    }
}
