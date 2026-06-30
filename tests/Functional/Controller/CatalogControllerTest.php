<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

final class CatalogControllerTest extends TestCase
{
    public function testGenresReturnsEmptyRoot(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/genres/', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
    }

    public function testArtistsReturnsPaginatedList(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/artists/', $user);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
    }

    public function testAlbumsReturnsPaginatedList(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/albums/', $user);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
    }

    public function testSongsReturnsPaginatedList(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/songs/', $user);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);

        $meta = $data['meta'];
        $this->assertArrayHasKey('next_cursor', $meta);
        $this->assertArrayHasKey('prev_cursor', $meta);
        $this->assertArrayHasKey('has_next_page', $meta);
        $this->assertArrayHasKey('has_previous_page', $meta);
        $this->assertArrayHasKey('total', $meta);
        $this->assertArrayHasKey('per_page', $meta);
        $this->assertArrayHasKey('stale_cursor', $meta);

        // First page: no previous cursor
        $this->assertNull($meta['prev_cursor']);
        $this->assertFalse($meta['has_previous_page']);

        // Default per_page is 50
        $this->assertSame(50, $meta['per_page']);
    }

    public function testSongsReturnsCursorPaginatedResponse(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/songs/', $user);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Response shape: { data: [...], meta: { ... } }
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertIsArray($data['data']);
        $this->assertIsArray($data['meta']);

        // Meta keys
        $meta = $data['meta'];
        $this->assertArrayHasKey('next_cursor', $meta);
        $this->assertArrayHasKey('prev_cursor', $meta);
        $this->assertArrayHasKey('has_next_page', $meta);
        $this->assertArrayHasKey('has_previous_page', $meta);
        $this->assertArrayHasKey('total', $meta);
        $this->assertArrayHasKey('per_page', $meta);
        $this->assertArrayHasKey('stale_cursor', $meta);

        // On first page with no items: prev_cursor is null, has_previous_page is false
        $this->assertNull($meta['prev_cursor']);
        $this->assertFalse($meta['has_previous_page']);

        // stale_cursor should be false on first request
        $this->assertFalse($meta['stale_cursor']);

        // Custom limit
        $response = $this->authenticatedRequest('GET', '/api/songs/?limit=5', $user);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(5, $data['meta']['per_page']);

        // Malformed cursor falls back to first page (graceful handling)
        $response = $this->authenticatedRequest('GET', '/api/songs/?cursor=invalid', $user);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNull($data['meta']['prev_cursor']);
        $this->assertFalse($data['meta']['has_previous_page']);
    }
}
