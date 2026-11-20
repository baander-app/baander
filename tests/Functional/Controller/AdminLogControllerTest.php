<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminLogControllerTest extends TestCase
{
    /** @return iterable<string, array{string,string}> */
    public static function routes(): iterable
    {
        yield 'sources' => ['GET', '/api/admin/logs/sources'];
        yield 'entries' => ['GET', '/api/admin/logs/entries'];
        yield 'detail' => ['GET', '/api/admin/logs/entries/unknown'];
        yield 'sql' => ['POST', '/api/admin/logs/query'];
    }

    #[DataProvider('routes')]
    public function testAnonymousCannotReadLogs(string $method, string $path): void
    {
        $this->assertJsonResponse($this->anonymousRequest($method, $path, $method === 'POST' ? ['sql' => 'SELECT 1'] : []), 401);
    }

    #[DataProvider('routes')]
    public function testOrdinaryAdminCannotReadLogs(string $method, string $path): void
    {
        $this->assertJsonResponse($this->authenticatedRequest($method, $path, $this->createAdminUser(), $method === 'POST' ? ['sql' => 'SELECT 1'] : []), 403);
    }

    public function testSuperAdminGetsCoverageEvenWhenNoNodeAnswers(): void
    {
        $response = $this->authenticatedRequest('GET', '/api/admin/logs/sources', $this->createSuperAdminUser());
        $data = $this->assertJsonResponse($response, 200, 'data');
        self::assertArrayHasKey('items', $data['data']);
        self::assertArrayHasKey('nodes', $data['data']);
        self::assertIsArray($data['data']['channels']);
        self::assertIsBool($data['data']['coverage']['complete']);
    }

    public function testMalformedPageLimitIsRejected(): void
    {
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/admin/logs/entries?limit=oops', $this->createSuperAdminUser()), 422);
    }
    public function testMultipleChannelQueryUsesArrayParameters(): void
    {
        $response = $this->authenticatedRequest('GET', '/api/admin/logs/entries?channels[]=app&channels[]=security', $this->createSuperAdminUser());
        $this->assertJsonResponse($response, 200, 'data');
    }

    public function testLegacyScalarChannelQueryIsRejected(): void
    {
        $response = $this->authenticatedRequest('GET', '/api/admin/logs/entries?channel=app', $this->createSuperAdminUser());
        $this->assertJsonResponse($response, 422);
    }

}
