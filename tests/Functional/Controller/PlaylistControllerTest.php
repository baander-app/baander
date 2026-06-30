<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

final class PlaylistControllerTest extends TestCase
{
    public function testCreatePlaylistRequiresAuth(): void
    {
        $response = $this->anonymousRequest('POST', '/api/playlists/', [
            'name' => 'My Playlist',
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testCreatePlaylist(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/playlists/', $user, [
            'name' => 'My Playlist',
            'description' => 'A test playlist',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('My Playlist', $data['name']);
    }

    public function testIndexReturnsUserPlaylists(): void
    {
        $user = $this->createTestUser();

        $this->authenticatedRequest('POST', '/api/playlists/', $user, [
            'name' => 'Playlist 1',
        ]);

        $response = $this->authenticatedRequest('GET', '/api/playlists/', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
        $this->assertCount(1, $data['data']);
    }

    public function testDeletePlaylist(): void
    {
        $user = $this->createTestUser();

        $createResponse = $this->authenticatedRequest('POST', '/api/playlists/', $user, [
            'name' => 'To Delete',
        ]);

        $createdData = json_decode($createResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $publicId = $createdData['publicId'];

        $deleteResponse = $this->authenticatedRequest('DELETE', '/api/playlists/' . $publicId, $user);
        $this->assertSame(204, $deleteResponse->getStatusCode());
    }
}
