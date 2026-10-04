<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminLoginBlockPaginationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidQueries(): iterable
    {
        foreach (['limit' => ['-1', '0', '101', 'abc', '1.5', '1e2', '', '999999999999999999999999'], 'offset' => ['-1', 'abc', '1.5', '', '999999999999999999999999']] as $field => $values) {
            foreach ($values as $value) {
                yield $field . '=' . $value => [$field . '=' . $value];
            }
            yield $field . ' array' => [$field . '[]=1'];
        }
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidPaginationReturnsBadRequest(string $query): void
    {
        $response = $this->authenticatedRequest('GET', '/api/admin/login-blocks?' . $query, $this->createAdminUser());

        $data = $this->assertJsonResponse($response, 400);
        self::assertArrayHasKey('error', $data);
        self::assertSame(400, $data['error']['code']);
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function validQueries(): iterable
    {
        yield 'defaults' => ['', 50, 0];
        yield 'smallest page' => ['?limit=1&offset=0', 1, 0];
        yield 'largest page' => ['?limit=100&offset=1', 100, 1];
    }

    #[DataProvider('validQueries')]
    public function testValidPaginationReturnsConsistentMetadata(string $query, int $limit, int $offset): void
    {
        $response = $this->authenticatedRequest('GET', '/api/admin/login-blocks' . $query, $this->createAdminUser());

        $data = $this->assertJsonResponse($response, 200);
        self::assertSame($limit, $data['meta']['limit']);
        self::assertSame($offset, $data['meta']['offset']);
        self::assertLessThanOrEqual($limit, count($data['data']));
    }
}
