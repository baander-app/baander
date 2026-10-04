<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NotificationQueryValidationTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function invalidQueries(): iterable
    {
        foreach ([
            'category' => ['invalid', '', ['security']],
            'unread' => ['invalid', '', ['true']],
            'limit' => ['0', '101', '1.5', 'invalid', ['1']],
            'cursor' => ['not-a-uuid', '', ['not-a-uuid']],
            'since' => ['tomorrow', '2026-10-01', '2026-02-31T00:00:00Z', '0000-01-01T00:00:00Z', '2026-10-01T00:00:00', '', ['2026-10-01T00:00:00Z']],
        ] as $field => $values) {
            foreach ($values as $index => $value) {
                yield $field . '-' . $index => [http_build_query([$field => $value]), $field];
            }
        }
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryReturnsFieldError(string $query, string $field): void
    {
        $response = $this->authenticatedRequest('GET', '/api/notifications/?' . $query, $this->createTestUser());

        $data = $this->assertJsonResponse($response, 400);
        self::assertArrayHasKey('error', $data);
        self::assertSame(400, $data['error']['code']);
        self::assertArrayHasKey($field, $data['error']['details']);
    }
}
