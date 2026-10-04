<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;
use App\UserPreference\Application\Port\EqDeviceProfilePortInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for EQ device profile management.
 *
 * Covers EqDeviceProfileController:
 *   GET    /api/user/eq-profiles/           list
 *   POST   /api/user/eq-profiles/           create (201)
 *   GET    /api/user/eq-profiles/{id}       show
 *   PUT    /api/user/eq-profiles/{id}       update
 *   DELETE /api/user/eq-profiles/{id}       delete (422 for default)
 *   POST   /api/user/eq-profiles/{id}/activate  activate
 *
 * Profile reads and mutations require the requesting owner; unrelated admins have
 * no bypass. Missing, malformed, and foreign profile IDs all return 404.
 */
final class EqDeviceProfileControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET / (index)
    // ---------------------------------------------------------------

    public function testIndexRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/eq-profiles/');

        $this->assertJsonResponse($response, 401);
    }

    public function testIndexReturnsEmptyForNewUser(): void
    {
        $user = $this->createTestUser();

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/eq-profiles/', $user),
            200,
            'data',
        );

        $this->assertSame([], $data['data']['profiles']);
    }

    // ---------------------------------------------------------------
    // POST / (create)
    // ---------------------------------------------------------------

    public function testCreateRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('POST', '/api/user/eq-profiles/', [
            'name' => 'Headphones',
            'icon' => 'headphones',
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testCreateReturns201AndPersists(): void
    {
        $user = $this->createTestUser();

        $data = $this->assertJsonResponse(
            $this->createProfile($user, 'Studio Headphones', 'headphones'),
            201,
            'data',
        );

        $this->assertSame('Studio Headphones', $data['data']['name']);
        $this->assertSame('headphones', $data['data']['icon']);
        $this->assertFalse($data['data']['isDefault']);

        // Visible in the list.
        $listData = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/eq-profiles/', $user),
            200,
            'data',
        );
        $this->assertCount(1, $listData['data']['profiles']);
    }

    public function testCreateWithBlankNameFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->createProfile($user, '', 'headphones');

        $this->assertJsonResponse($response, 422);
    }

    public function testCreateWithInvalidIconFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->createProfile($user, 'My Profile', 'nonexistent-icon');

        $this->assertJsonResponse($response, 422);
    }

    public function testCreateAcceptsCustomIcon(): void
    {
        $user = $this->createTestUser();

        $response = $this->createProfile($user, 'Custom Device', 'custom');

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    }

    // ---------------------------------------------------------------
    // GET /{id} (show)
    // ---------------------------------------------------------------

    public function testShowRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/eq-profiles/' . $this->zeroUuid());

        $this->assertJsonResponse($response, 401);
    }

    public function testShowReturnsProfileDetails(): void
    {
        $user = $this->createTestUser();
        $created = $this->assertJsonResponse($this->createProfile($user, 'Speakers', 'speakers'), 201, 'data');
        $id = $created['data']['id'];

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/eq-profiles/' . $id, $user),
            200,
            'data',
        );

        $this->assertSame('Speakers', $data['data']['name']);
    }

    // ---------------------------------------------------------------
    // PUT /{id} (update)
    // ---------------------------------------------------------------

    public function testUpdateRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('PUT', '/api/user/eq-profiles/' . $this->zeroUuid(), ['name' => 'X']);

        $this->assertJsonResponse($response, 401);
    }

    public function testUpdateChangesName(): void
    {
        $user = $this->createTestUser();
        $created = $this->assertJsonResponse($this->createProfile($user, 'Old Name', 'headphones'), 201, 'data');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('PUT', '/api/user/eq-profiles/' . $created['data']['id'], $user, [
                'name' => 'New Name',
            ]),
            200,
            'data',
        );

        $this->assertSame('New Name', $data['data']['name']);
    }

    public function testUpdateIncrementsVersionWhenPayloadChanges(): void
    {
        $user = $this->createTestUser();
        $created = $this->assertJsonResponse($this->createProfile($user, 'P', 'headphones'), 201, 'data');
        $originalVersion = $created['data']['version'];

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('PUT', '/api/user/eq-profiles/' . $created['data']['id'], $user, [
                'payload' => ['bands' => [1, 2, 3]],
            ]),
            200,
            'data',
        );

        $this->assertSame($originalVersion + 1, $data['data']['version']);
    }

    // ---------------------------------------------------------------
    // DELETE /{id}
    // ---------------------------------------------------------------

    public function testDeleteRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('DELETE', '/api/user/eq-profiles/' . $this->zeroUuid());

        $this->assertJsonResponse($response, 401);
    }

    public function testDeleteRemovesProfile(): void
    {
        $user = $this->createTestUser();
        $created = $this->assertJsonResponse($this->createProfile($user, 'To Delete', 'headphones'), 201, 'data');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('DELETE', '/api/user/eq-profiles/' . $created['data']['id'], $user),
            200,
            'data',
        );

        $this->assertTrue($data['data']['deleted']);

        // Gone from the list.
        $listData = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/eq-profiles/', $user),
            200,
            'data',
        );
        $this->assertSame([], $listData['data']['profiles']);
    }

    // ---------------------------------------------------------------
    // POST /{id}/activate
    // ---------------------------------------------------------------

    public function testActivateRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('POST', '/api/user/eq-profiles/' . $this->zeroUuid() . '/activate');

        $this->assertJsonResponse($response, 401);
    }

    public function testActivateReturnsActiveProfileId(): void
    {
        $user = $this->createTestUser();
        $created = $this->assertJsonResponse($this->createProfile($user, 'Active', 'headphones'), 201, 'data');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/user/eq-profiles/' . $created['data']['id'] . '/activate', $user),
            200,
            'data',
        );

        $this->assertSame($created['data']['id'], $data['data']['activeProfileId']);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function profileOperations(): iterable
    {
        yield 'show' => ['GET', '', []];
        yield 'update' => ['PUT', '', ['name' => 'Hacked', 'payload' => ['bands' => [12]]]];
        yield 'delete' => ['DELETE', '', []];
        yield 'activate' => ['POST', '/activate', []];
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, bool}> */
    public static function foreignProfileOperations(): iterable
    {
        foreach (self::profileOperations() as $operation => $arguments) {
            yield $operation . ' ordinary user' => [...$arguments, false];
            yield $operation . ' unrelated admin' => [...$arguments, true];
        }
    }

    /** @param array<string, mixed> $content */
    #[DataProvider('foreignProfileOperations')]
    public function testForeignProfileIsNotAccessibleOrMutated(string $method, string $suffix, array $content, bool $admin): void
    {
        $owner = $this->createTestUser();
        $intruder = $admin ? $this->createAdminUser() : $this->createTestUser();
        $created = $this->assertJsonResponse($this->createProfile($owner, 'Private EQ Profile', 'headphones', 'private-device-id'), 201, 'data');
        $uri = '/api/user/eq-profiles/' . $created['data']['id'];
        $response = $this->authenticatedRequest($method, $uri . $suffix, $intruder, $content);
        $error = $this->assertJsonResponse($response, 404);
        $this->assertArrayNotHasKey('data', $error);
        $this->assertStringNotContainsString('Private EQ Profile', $response->getContent());
        $this->assertStringNotContainsString('private-device-id', $response->getContent());
        $this->entityManager->clear();
        $stored = $this->assertJsonResponse($this->authenticatedRequest('GET', $uri, $owner), 200, 'data');
        $this->assertSame($created['data'], $stored['data']);
    }

    /** @param array<string, mixed> $content */
    #[DataProvider('profileOperations')]
    public function testMissingProfileReturns404(string $method, string $suffix, array $content): void
    {
        $user = $this->createTestUser();
        $this->assertJsonResponse($this->authenticatedRequest($method, '/api/user/eq-profiles/' . $this->zeroUuid() . $suffix, $user, $content), 404);
    }

    /** @param array<string, mixed> $content */
    #[DataProvider('profileOperations')]
    public function testMalformedProfileIdReturns404(string $method, string $suffix, array $content): void
    {
        $user = $this->createTestUser();
        $this->assertJsonResponse($this->authenticatedRequest($method, '/api/user/eq-profiles/invalid-id' . $suffix, $user, $content), 404);
    }

    public function testDefaultProfileDeletionRemainsOwnerOnlyAndProtected(): void
    {
        $owner = $this->createTestUser();
        $admin = $this->createAdminUser();
        $port = static::getContainer()->get(EqDeviceProfilePortInterface::class);
        $profile = $port->createProfile($owner->getId(), 'Private Default', 'custom', null, [], isDefault: true);
        $uri = '/api/user/eq-profiles/' . $profile['id'];
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', $uri, $admin), 404);
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', $uri, $owner), 422);
        $this->entityManager->clear();
        $stored = $this->assertJsonResponse($this->authenticatedRequest('GET', $uri, $owner), 200, 'data');
        $this->assertSame($profile, $stored['data']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createProfile(User $user, string $name, string $icon, ?string $deviceId = null): Response
    {
        return $this->authenticatedRequest('POST', '/api/user/eq-profiles/', $user, [
            'name' => $name,
            'icon' => $icon,
            'deviceId' => $deviceId,
        ]);
    }

    private function zeroUuid(): string
    {
        return '00000000-0000-0000-0000-000000000000';
    }
}
