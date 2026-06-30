<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Tests\Functional\TestCase;

final class RecommendationControllerTest extends TestCase
{
    private RecommendationRepositoryInterface $recommendationRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $container = static::getContainer();
        $this->recommendationRepository = $container->get(RecommendationRepositoryInterface::class);

        $entityManager = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\Recommendation\Infrastructure\Doctrine\Entity\RecommendationEntity')->execute();
        $entityManager->flush();
    }

    public function testIndexRequiresAuth(): void
    {
        $response = $this->anonymousRequest('GET', '/api/recommendations/');

        $this->assertJsonResponse($response, 401);
    }

    public function testIndexReturnsEmptyArrayForNewUser(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/recommendations/', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
        $this->assertEmpty($data['data']);
    }

    public function testIndexReturnsUserRecommendations(): void
    {
        $user = $this->createTestUser();

        $recommendation = Recommendation::create(
            sourceType: 'song',
            sourceId: 'song-1',
            targetType: 'song',
            targetId: 'song-2',
            score: 0.9,
            userId: $user->getId(),
            name: 'default',
        );
        $this->recommendationRepository->save($recommendation);

        $response = $this->authenticatedRequest('GET', '/api/recommendations/', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
        $this->assertCount(1, $data['data']);
        $this->assertSame('song-2', $data['data'][0]['target_id']);
    }

    public function testIndexRespectsLimit(): void
    {
        $user = $this->createTestUser();

        for ($i = 0; $i < 5; $i++) {
            $recommendation = Recommendation::create(
                sourceType: 'song',
                sourceId: "song-src-$i",
                targetType: 'song',
                targetId: "song-tgt-$i",
                score: (float)$i,
                userId: $user->getId(),
                name: 'default',
            );
            $this->recommendationRepository->save($recommendation);
        }

        $response = $this->authenticatedRequest('GET', '/api/recommendations/?limit=2', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertCount(2, $data['data']);
    }

    public function testBySourceReturnsRecommendations(): void
    {
        $user = $this->createTestUser();

        $recommendation = Recommendation::create(
            sourceType: 'song',
            sourceId: 'song-src-1',
            targetType: 'song',
            targetId: 'song-tgt-1',
            score: 0.85,
            name: 'default',
        );
        $this->recommendationRepository->save($recommendation);

        $response = $this->authenticatedRequest('GET', '/api/recommendations/source/song/song-src-1', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
        $this->assertCount(1, $data['data']);
        $this->assertSame('song-tgt-1', $data['data'][0]['target_id']);
    }

    public function testTargetingReturnsRecommendations(): void
    {
        $user = $this->createTestUser();

        $recommendation = Recommendation::create(
            sourceType: 'song',
            sourceId: 'song-src-1',
            targetType: 'song',
            targetId: 'song-tgt-1',
            score: 0.75,
            name: 'default',
        );
        $this->recommendationRepository->save($recommendation);

        $response = $this->authenticatedRequest('GET', '/api/recommendations/targeting/song/song-tgt-1', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
        $this->assertCount(1, $data['data']);
        $this->assertSame('song-src-1', $data['data'][0]['source_id']);
    }

    public function testStoreRequiresAuth(): void
    {
        $response = $this->anonymousRequest('POST', '/api/recommendations/', [
            'source_type' => 'song',
            'source_id'   => 'song-1',
            'target_type' => 'song',
            'target_id'   => 'song-2',
            'score'       => 0.8,
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testStoreCreatesRecommendation(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/recommendations/', $user, [
            'source_type' => 'song',
            'source_id'   => 'song-1',
            'target_type' => 'song',
            'target_id'   => 'song-2',
            'score'       => 0.9,
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Recommendation created.', $data['message']);
    }

    public function testStoreRejectsInvalidSourceType(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/recommendations/', $user, [
            'source_type' => 'invalid',
            'source_id'   => 'song-1',
            'target_type' => 'song',
            'target_id'   => 'song-2',
            'score'       => 0.9,
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDestroyRequiresAuth(): void
    {
        $response = $this->anonymousRequest('DELETE', '/api/recommendations/00000000-0000-0000-0000-000000000001');

        $this->assertJsonResponse($response, 401);
    }

    public function testDestroyRejectsInvalidUuid(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('DELETE', '/api/recommendations/not-a-uuid', $user);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDestroyBySourceRequiresAuth(): void
    {
        $response = $this->anonymousRequest('DELETE', '/api/recommendations/source/song/song-1');

        $this->assertJsonResponse($response, 401);
    }

    public function testDestroyBySourceDeletesRecommendations(): void
    {
        $user = $this->createTestUser();

        $recommendation = Recommendation::create(
            sourceType: 'song',
            sourceId: 'song-to-delete',
            targetType: 'song',
            targetId: 'song-tgt-1',
            score: 0.5,
            userId: $user->getId(),
            name: 'default',
        );
        $this->recommendationRepository->save($recommendation);

        $response = $this->authenticatedRequest('DELETE', '/api/recommendations/source/song/song-to-delete', $user);

        $this->assertSame(204, $response->getStatusCode());
    }
}
