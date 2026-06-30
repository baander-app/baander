<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;

final class ActivityControllerTest extends TestCase
{
    public function testPlayRequiresAuth(): void
    {
        $response = $this->anonymousRequest('POST', '/api/activity/play', [
            'songId' => (new \App\Shared\Domain\Model\PublicId())->toString(),
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testRecordPlay(): void
    {
        $user = $this->createTestUser();
        $songId = (new \App\Shared\Domain\Model\PublicId())->toString();

        $response = $this->authenticatedRequest('POST', '/api/activity/play', $user, [
            'songId' => $songId,
            'platform' => 'web',
            'player' => 'browser',
        ]);

        $data = $this->assertJsonResponse($response, 201, 'data');
        $this->assertArrayHasKey('songId', $data['data']);
    }

    public function testHistoryReturnsEmptyList(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/activity/history', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
    }

    public function testLovedRequiresAuth(): void
    {
        $response = $this->anonymousRequest('GET', '/api/activity/loved');

        $this->assertJsonResponse($response, 401);
    }

    public function testRecordPlayAndToggleLove(): void
    {
        $user = $this->createTestUser();
        $songId = (new \App\Shared\Domain\Model\PublicId())->toString();

        // Record a play
        $playResponse = $this->authenticatedRequest('POST', '/api/activity/play', $user, [
            'songId' => $songId,
        ]);
        $playData = json_decode($playResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $publicId = $playData['data']['publicId'];

        // Toggle love
        $loveResponse = $this->authenticatedRequest('POST', '/api/activity/love/' . $publicId, $user);
        $loveData = $this->assertJsonResponse($loveResponse, 200, 'data');
        $this->assertTrue($loveData['data']['love']);
    }
}
