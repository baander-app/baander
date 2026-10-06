<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

final class LibraryControllerTest extends TestCase
{
    public function testIndexReturnsEmptyList(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/libraries', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
    }

    public function testStoreCreatesLibrary(): void
    {
        $user = $this->createAdminUser();
        $uniqueSuffix = bin2hex(random_bytes(4));

        $response = $this->authenticatedRequest('POST', '/api/libraries', $user, [
            'name' => 'My Music ' . $uniqueSuffix,
            'path' => '/media/music-' . $uniqueSuffix,
            'type' => 'music',
        ]);

        $data = $this->assertJsonResponse($response, 201, 'data');
        $this->assertStringContainsString('My Music', $data['data']['name']);
    }

    public function testStoreRejectsInvalidType(): void
    {
        $user = $this->createAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/libraries', $user, [
            'name' => 'Bad Library',
            'path' => '/media/bad',
            'type' => 'invalid_type',
        ]);

        $this->assertJsonResponse($response, 400);
    }

    public function testStoreRejectsRelativePath(): void
    {
        $user = $this->createAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/libraries', $user, [
            'name' => 'Bad Library',
            'path' => 'relative/path',
            'type' => 'music',
        ]);

        $this->assertJsonResponse($response, 400);
    }

    public function testStoreGrantsCreatorAccess(): void
    {
        $user = $this->createAdminUser();
        $uniqueSuffix = bin2hex(random_bytes(4));

        $response = $this->authenticatedRequest('POST', '/api/libraries', $user, [
            'name' => 'Owned Music ' . $uniqueSuffix,
            'path' => '/media/owned-' . $uniqueSuffix,
            'type' => 'music',
        ]);

        $data = $this->assertJsonResponse($response, 201, 'data');
        $libraryId = \App\Shared\Domain\Model\Uuid::fromString($data['data']['id']);
        $userId = \App\Shared\Domain\Model\Uuid::fromString($user->getId()->toString());

        $libraryAccess = static::getContainer()->get(\App\Library\Application\Port\LibraryAccessPortInterface::class);

        $this->assertTrue($libraryAccess->hasAccess($userId, $libraryId));
    }

    public function testStoreIsForbiddenForNonAdmin(): void
    {
        $user = $this->createTestUser();
        $slug = 'root-' . bin2hex(random_bytes(4));

        $response = $this->authenticatedRequest('POST', '/api/libraries', $user, [
            'name' => 'Server Config',
            'slug' => $slug,
            'path' => '/etc',
            'type' => 'music',
        ]);

        $this->assertJsonResponse($response, 403);
        $libraries = static::getContainer()->get(\App\Library\Application\Port\LibraryPortInterface::class);
        $this->assertNull($libraries->findBySlug(new \App\Library\Domain\ValueObject\LibrarySlug($slug)));
    }

    public function testValidatePathIsForbiddenForNonAdmin(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/libraries/validate-path', $user, [
            'path' => '/etc',
        ]);

        $data = $this->assertJsonResponse($response, 403);
        $this->assertArrayNotHasKey('data', $data);
        foreach (['valid', 'exists', 'readable', 'resolvedPath'] as $field) {
            $this->assertStringNotContainsString('"' . $field . '"', (string) $response->getContent());
        }
    }

    public function testValidatePathReportsResultForAdmin(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/libraries/validate-path', $admin, [
            'path' => '/etc',
        ]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertTrue($data['data']['exists']);
        $this->assertSame('/etc', $data['data']['resolvedPath']);
        $this->assertArrayHasKey('valid', $data['data']);
        $this->assertArrayHasKey('readable', $data['data']);
    }
}
