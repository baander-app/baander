<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class RecommendationControllerTest extends TestCase
{
    private RecommendationRepositoryInterface $recommendations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recommendations = static::getContainer()->get(RecommendationRepositoryInterface::class);
    }

    public function testPublicReferencesRoundTripWithoutInternalIds(): void
    {
        $user = $this->createTestUser();
        [$source, $sourcePublic] = $this->song($user);
        [$target, $targetPublic] = $this->song($user);
        $rec = Recommendation::create('song', $source, 'song', $target, 0.9, $user->getId());
        $this->recommendations->save($rec);
        foreach (['/api/recommendations/', '/api/recommendations/source/song/' . $sourcePublic, '/api/recommendations/targeting/song/' . $targetPublic] as $path) {
            $response = $this->authenticatedRequest('GET', $path, $user);
            $data = $this->assertJsonResponse($response, 200, 'data')['data'];
            self::assertCount(1, $data);
            self::assertSame($rec->getPublicId()->toString(), $data[0]['public_id']);
            self::assertSame($sourcePublic, $data[0]['source_id']);
            self::assertSame($targetPublic, $data[0]['target_id']);
            self::assertSame($user->getPublicId()->toString(), $data[0]['user_id']);
            foreach ([$source, $target, $user->getId()->toString(), $rec->getId()->toString()] as $internal) { self::assertStringNotContainsString($internal, (string) $response->getContent()); }
        }
        self::assertSame($rec->getId()->toString(), $this->recommendations->findByPublicId($rec->getPublicId())?->getId()->toString());
    }

    public function testCreationRequiresPublicReferencesAndKeepsInternalPersistence(): void
    {
        $user = $this->createTestUser();
        [$source, $sourcePublic] = $this->song($user);
        [$target, $targetPublic] = $this->song($user);
        $payload = ['source_type' => 'song', 'source_id' => $sourcePublic, 'target_type' => 'song', 'target_id' => $targetPublic, 'score' => 0.9];
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/recommendations/', $user, $payload), 201);
        self::assertCount(1, $this->recommendations->findBySource('song', $source));
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/recommendations/', $user, array_replace($payload, ['source_id' => $source])), 404);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/recommendations/', $user, array_replace($payload, ['target_id' => $target])), 404);
        self::assertCount(1, $this->recommendations->findBySource('song', $source));
    }

    public function testInternalAndHiddenEntityAddressesCannotBeResolved(): void
    {
        $owner = $this->createTestUser();
        $other = $this->createTestUser();
        [$internal, $public] = $this->song($owner);
        foreach ([$internal, $public] as $address) {
            $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/recommendations/source/song/' . $address, $other), 404);
        }
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/recommendations/source/song/' . $internal, $owner), 404);
        $rec = Recommendation::create('song', $internal, 'song', $internal, 0.5, $other->getId());
        $this->recommendations->save($rec);
        self::assertSame([], $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/recommendations/', $other), 200, 'data')['data']);
    }

    public function testDeletionUsesPublicRecommendationIdentityAndOwnership(): void
    {
        $owner = $this->createTestUser();
        [$source] = $this->song($owner);
        $rec = Recommendation::create('song', $source, 'song', $source, 0.5, $owner->getId());
        $this->recommendations->save($rec);
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/recommendations/' . $rec->getId()->toString(), $owner), 404);
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/recommendations/' . $rec->getPublicId()->toString(), $this->createTestUser()), 404);
        self::assertNotNull($this->recommendations->findByUuid($rec->getId()));
        self::assertSame(204, $this->authenticatedRequest('DELETE', '/api/recommendations/' . $rec->getPublicId()->toString(), $owner)->getStatusCode());
        self::assertNull($this->recommendations->findByUuid($rec->getId()));
    }

    public function testSharedLibraryDoesNotExposeOrDeleteAnotherUsersRecommendation(): void
    {
        $owner = $this->createTestUser();
        $other = $this->createTestUser();
        [$source, $public] = $this->song($owner);
        $db = $this->entityManager->getConnection();
        $library = $db->fetchOne('SELECT album.library_id FROM songs song JOIN albums album ON album.id = song.album_id WHERE song.id = ?', [$source]);
        $db->insert('user_library_access', ['user_id' => $other->getId()->toString(), 'library_id' => $library, 'granted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP')]);
        $rec = Recommendation::create('song', $source, 'song', $source, 0.5, $owner->getId());
        $this->recommendations->save($rec);
        self::assertSame([], $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/recommendations/source/song/' . $public, $other), 200, 'data')['data']);
        self::assertSame(204, $this->authenticatedRequest('DELETE', '/api/recommendations/source/song/' . $public, $other)->getStatusCode());
        self::assertNotNull($this->recommendations->findByUuid($rec->getId()));
        self::assertSame(204, $this->authenticatedRequest('DELETE', '/api/recommendations/source/song/' . $public, $owner)->getStatusCode());
        $this->entityManager->clear();
        self::assertNull($this->recommendations->findByUuid($rec->getId()));
    }

    public function testAnonymousRequestsRemainRejected(): void
    {
        foreach (['GET', 'POST', 'DELETE'] as $method) {
            $this->assertJsonResponse($this->anonymousRequest($method, '/api/recommendations/' . ($method === 'DELETE' ? (new PublicId())->toString() : '')), 401);
        }
    }

    /** @return array{string, string} Internal and public identities deliberately differ. */
    private function song(User $user): array
    {
        $db = $this->entityManager->getConnection();
        $library = Uuid::v7()->toString();
        $album = Uuid::v7()->toString();
        $song = Uuid::v7()->toString();
        $public = (new PublicId())->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');
        $db->insert('libraries', ['id' => $library, 'public_id' => (new PublicId())->toString(), 'slug' => 'recommendation-' . bin2hex(random_bytes(6)), 'name' => 'Recommendation fixture', 'path' => '/tmp/recommendation-' . bin2hex(random_bytes(6)), 'type' => 'music', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $db->insert('user_library_access', ['user_id' => $user->getId()->toString(), 'library_id' => $library, 'granted_at' => $now]);
        $db->insert('albums', ['id' => $album, 'public_id' => (new PublicId())->toString(), 'library_id' => $library, 'title' => 'Recommendation album', 'type' => 'studio', 'locked_fields' => '{}', 'merged_from' => '[]', 'created_at' => $now, 'updated_at' => $now]);
        $db->insert('songs', ['id' => $song, 'public_id' => $public, 'album_id' => $album, 'path' => '/tmp/' . $public . '.mp3', 'hash' => hash('sha256', $song), 'title' => 'Recommendation song', 'size' => 1000, 'mime_type' => 'audio/mpeg', 'created_at' => $now, 'updated_at' => $now]);
        return [$song, $public];
    }
}
