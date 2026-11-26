<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Recommendation\Application\Command\SaveRecommendationCommand;
use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Recommendation\Domain\ValueObject\RecommendationType;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

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
        self::assertCount(1, $this->recommendations->findBySource('song', $source, null));
        $withInternalSource = array_replace($payload, ['source_id' => $source]);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/recommendations/', $user, $withInternalSource), 404);
        $withInternalTarget = array_replace($payload, ['target_id' => $target]);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/recommendations/', $user, $withInternalTarget), 404);
        self::assertCount(1, $this->recommendations->findBySource('song', $source, null));
    }

    public function testAnEmptyBodyIsAValidationErrorNamingEveryRequiredField(): void
    {
        $response = $this->postJson($this->createTestUser(), '{}');

        $details = $this->assertJsonResponse($response, 422, 'error')['error']['details'] ?? [];
        self::assertSame(['score', 'source_id', 'source_type', 'target_id', 'target_type'], array_keys($details) |> self::sorted(...));
    }

    /** @return iterable<string, array{string, string}> the field and the JSON value sent for it */
    public static function valuesOutsideTheirBounds(): iterable
    {
        yield '256-character name' => ['name', '"' . str_repeat('n', 256) . '"'];
        yield 'score that overflows to infinity' => ['score', '1e400'];
        yield 'negative infinite score' => ['score', '-1e400'];
        yield 'score above the generator range' => ['score', '1.5'];
        yield 'score below the generator range' => ['score', '-0.01'];
        yield 'score sent as text' => ['score', '"0.5"'];
        yield 'position beyond the stored integer' => ['position', '2147483648'];
        yield 'unknown source type' => ['source_type', '"playlist"'];
    }

    #[DataProvider('valuesOutsideTheirBounds')]
    public function testAValueOutsideItsBoundIsAValidationErrorAndStoresNothing(string $field, string $json): void
    {
        $user = $this->createTestUser();
        [$source, $sourcePublic] = $this->song($user);
        [, $targetPublic] = $this->song($user);
        $fields = ['source_type' => '"song"', 'source_id' => '"' . $sourcePublic . '"', 'target_type' => '"song"', 'target_id' => '"' . $targetPublic . '"', 'score' => '0.5', $field => $json];
        $keys = array_keys($fields);
        $body = '{' . implode(',', array_map(static fn (string $key, string $value): string => '"' . $key . '":' . $value, $keys, $fields)) . '}';

        $details = $this->assertJsonResponse($this->postJson($user, $body), 422, 'error')['error']['details'] ?? [];

        self::assertArrayHasKey($field, $details, json_encode($details, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->recommendations->findBySource('song', $source, null));
    }

    public function testTheLargestValuesValidationAcceptsAreStored(): void
    {
        $user = $this->createTestUser();
        [, $sourcePublic] = $this->song($user);
        [, $targetPublic] = $this->song($user);

        foreach (['0' => 0.0, '1' => 1.0] as $suffix => $score) {
            $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/recommendations/', $user, [
                'source_type' => 'song', 'source_id' => $sourcePublic, 'target_type' => 'song', 'target_id' => $targetPublic,
                'score' => $score, 'name' => str_repeat('ø', 254) . $suffix, 'position' => 2147483647,
            ]), 201);
        }

        self::assertSame([1.0, 0.0], array_map(
            static fn (Recommendation $stored): float => $stored->getScore(),
            array_filter(
                $this->recommendations->findForUser($user->getId(), 200),
                static fn (Recommendation $stored): bool => mb_strlen($stored->getName()) === 255 && $stored->getPosition() === 2147483647,
            ) |> array_values(...),
        ));
    }

    public function testARepeatedRecommendationIsAConflict(): void
    {
        $user = $this->createTestUser();
        [$source, $sourcePublic] = $this->song($user);
        [, $targetPublic] = $this->song($user);
        $payload = ['source_type' => 'song', 'source_id' => $sourcePublic, 'target_type' => 'song', 'target_id' => $targetPublic, 'score' => 0.5];

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/recommendations/', $user, $payload), 201);
        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/recommendations/', $user, $payload), 409, 'error')['error'];

        self::assertSame('recommendation_exists', $error['details']['reason'] ?? null);
        self::assertCount(1, $this->recommendations->findBySource('song', $source, null));
    }

    public function testTheThousandAndFirstApiRowIsRefusedAndTheGeneratorStillWritesTheUsersRows(): void
    {
        $user = $this->createTestUser();
        [$source, $sourcePublic] = $this->song($user);
        [$target, $targetPublic] = $this->song($user);
        $this->storeMemberRows($user, $source, 999);
        $payload = ['source_type' => 'song', 'source_id' => $sourcePublic, 'target_type' => 'song', 'target_id' => $targetPublic, 'score' => 0.5];

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/recommendations/', $user, $payload), 201);
        $overTheCap = array_replace($payload, ['name' => 'over the cap']);
        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/recommendations/', $user, $overTheCap), 409, 'error')['error'];

        self::assertSame(['reason' => 'recommendation_limit', 'limit' => 1000], $error['details'] ?? null);
        self::assertSame(1000, $this->storedRows($user));

        static::getContainer()->get(MessageBusInterface::class)->dispatch(new SaveRecommendationCommand(
            sourceType: RecommendationType::song(),
            sourceId: $source,
            targetType: RecommendationType::song(),
            targetId: $target,
            score: 0.25,
            userId: $user->getId(),
            name: 'collaborative',
        ));

        self::assertSame(1001, $this->storedRows($user));
        $stillOverTheCap = array_replace($payload, ['name' => 'still over the cap']);
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/recommendations/', $user, $stillOverTheCap), 409);
    }

    public function testAnotherMembersRowsDoNotCrowdTheViewersListings(): void
    {
        $crowder = $this->createTestUser();
        $viewer = $this->createTestUser();
        [$source, $sourcePublic] = $this->song($viewer);
        [$target, $targetPublic] = $this->song($viewer);
        $crowding = [];
        for ($i = 0; $i < 100; $i++) {
            $crowding[] = Recommendation::create('song', $source, 'song', Uuid::v7()->toString(), 1.0, $crowder->getId());
            $crowding[] = Recommendation::create('song', Uuid::v7()->toString(), 'song', $target, 1.0, $crowder->getId());
        }
        $this->recommendations->saveMany($crowding);
        $own = Recommendation::create('song', $source, 'song', $target, 0.1, $viewer->getId());
        $this->recommendations->save($own);

        foreach (['/api/recommendations/source/song/' . $sourcePublic, '/api/recommendations/targeting/song/' . $targetPublic] as $path) {
            $data = $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $viewer), 200, 'data')['data'];
            self::assertSame([$own->getPublicId()->toString()], array_column($data, 'public_id'), $path);
        }
    }

    public function testMembersSeeTheirOwnAndGlobalRowsAndAnAdministratorSeesEveryRow(): void
    {
        $owner = $this->createTestUser();
        $member = $this->createTestUser();
        $other = $this->createTestUser();
        [$source, $sourcePublic] = $this->song($owner);
        [$target] = $this->song($owner);
        [$secondTarget] = $this->song($owner);
        $this->grantLibraryOf($source, $member);
        $this->grantLibraryOf($target, $member);
        $global = Recommendation::create('song', $source, 'song', $target, 0.5);
        $owned = Recommendation::create('song', $source, 'song', $target, 0.9, $owner->getId());
        $foreign = Recommendation::create('song', $source, 'song', $secondTarget, 0.7, $other->getId());
        $this->recommendations->saveMany([$global, $owned, $foreign]);

        foreach ([
            'owner' => [$owner, [$owned, $global]],
            'member with access to the library' => [$member, [$global]],
            'administrator' => [$this->createAdminUser(), [$owned, $foreign, $global]],
        ] as $case => [$viewer, $expected]) {
            $data = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/recommendations/source/song/' . $sourcePublic, $viewer), 200, 'data')['data'];
            self::assertSame(
                array_map(static fn (Recommendation $row): string => $row->getPublicId()->toString(), $expected),
                array_column($data, 'public_id'),
                $case,
            );
        }
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
        $db->insert('user_library_access', ['user_id' => $other->getId()->toString(), 'library_id' => $library, 'granted_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s.uP')]);
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
            $this->assertJsonResponse($this->anonymousRequest($method, '/api/recommendations/' . ($method === 'DELETE' ? new PublicId()->toString() : '')), 401);
        }
    }

    private function postJson(User $user, string $body): Response
    {
        $this->client->request('POST', '/api/recommendations/', [], [], [
            'CONTENT_TYPE' => 'application/json',
            BaanderHeader::TestUserId->serverKey() => $user->getId()->toString(),
        ], $body);

        return $this->client->getResponse();
    }

    /** Rows the user wrote through the API, each recommending a song of its own. */
    private function storeMemberRows(User $user, string $source, int $count): void
    {
        $this->entityManager->getConnection()->executeStatement(<<<'SQL'
            INSERT INTO recommendations (id, public_id, user_id, name, source_type, source_id, target_type, target_id, score, origin, created_at, updated_at)
            SELECT gen_random_uuid(), substr(md5(random()::text), 1, 21), :user, 'default', 'song', :source, 'song', gen_random_uuid()::text, 0.5, 'member', now(), now()
            FROM generate_series(1, :count)
            SQL, ['user' => $user->getId()->toString(), 'source' => $source, 'count' => $count]);
    }

    private function storedRows(User $user): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM recommendations WHERE user_id = ?', [$user->getId()->toString()]);
    }

    private function grantLibraryOf(string $song, User $user): void
    {
        $db = $this->entityManager->getConnection();
        $library = $db->fetchOne('SELECT album.library_id FROM songs song JOIN albums album ON album.id = song.album_id WHERE song.id = ?', [$song]);
        $db->insert('user_library_access', ['user_id' => $user->getId()->toString(), 'library_id' => $library, 'granted_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s.uP')]);
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    /** @return array{string, string} Internal and public identities deliberately differ. */
    private function song(User $user): array
    {
        $db = $this->entityManager->getConnection();
        $library = Uuid::v7()->toString();
        $album = Uuid::v7()->toString();
        $song = Uuid::v7()->toString();
        $public = new PublicId()->toString();
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s.uP');
        $db->insert('libraries', ['id' => $library, 'public_id' => new PublicId()->toString(), 'slug' => 'recommendation-' . (random_bytes(6) |> bin2hex(...)), 'name' => 'Recommendation fixture', 'path' => '/tmp/recommendation-' . (random_bytes(6) |> bin2hex(...)), 'type' => 'music', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $db->insert('user_library_access', ['user_id' => $user->getId()->toString(), 'library_id' => $library, 'granted_at' => $now]);
        $db->insert('albums', ['id' => $album, 'public_id' => new PublicId()->toString(), 'library_id' => $library, 'title' => 'Recommendation album', 'type' => 'studio', 'locked_fields' => '{}', 'merged_from' => '[]', 'created_at' => $now, 'updated_at' => $now]);
        $db->insert('songs', ['id' => $song, 'public_id' => $public, 'album_id' => $album, 'path' => '/tmp/' . $public . '.mp3', 'hash' => hash('sha256', $song), 'title' => 'Recommendation song', 'size' => 1000, 'mime_type' => 'audio/mpeg', 'created_at' => $now, 'updated_at' => $now]);
        return [$song, $public];
    }
}
