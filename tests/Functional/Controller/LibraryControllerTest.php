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
        $user = $this->createTestUser();
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
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/libraries', $user, [
            'name' => 'Bad Library',
            'path' => '/media/bad',
            'type' => 'invalid_type',
        ]);

        $this->assertJsonResponse($response, 400);
    }

    public function testStoreRejectsRelativePath(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/libraries', $user, [
            'name' => 'Bad Library',
            'path' => 'relative/path',
            'type' => 'music',
        ]);

        $this->assertJsonResponse($response, 400);
    }

    public function testStoreGrantsCreatorAccess(): void
    {
        $user = $this->createTestUser();
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
}
