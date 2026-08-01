<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

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
 * Notable behaviour pinned here:
 *  - show/update/delete do NOT pass userId to the adapter — any authenticated
 *    user can access another user's profile (no ownership enforcement).
 *  - getProfile/updateProfile/deleteProfile throw InvalidArgumentException for
 *    a missing profile, which the controller does NOT catch → 500 instead of 404.
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

    public function testShowForMissingProfileReturns500(): void
    {
        // BUG PIN: getProfile throws InvalidArgumentException for a missing profile.
        // The controller does not catch it, so the ExceptionSubscriber maps it to 500
        // instead of the expected 404.
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/user/eq-profiles/' . $this->zeroUuid(), $user);

        $this->assertSame(500, $response->getStatusCode(), $response->getContent());
    }

    public function testShowDoesNotEnforceOwnership(): void
    {
        // BUG PIN: show() passes only the profileId (no userId), so any authenticated
        // user can read another user's EQ profile.
        $owner = $this->createTestUser();
        $intruder = $this->createTestUser();

        $created = $this->assertJsonResponse($this->createProfile($owner, 'Secret EQ', 'headphones'), 201, 'data');

        $response = $this->authenticatedRequest('GET', '/api/user/eq-profiles/' . $created['data']['id'], $intruder);

        $this->assertSame(200, $response->getStatusCode(), 'show has no ownership check (bug).');
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

    public function testUpdateDoesNotEnforceOwnership(): void
    {
        // BUG PIN: update() passes only the profileId — no ownership check.
        $owner = $this->createTestUser();
        $intruder = $this->createTestUser();
        $created = $this->assertJsonResponse($this->createProfile($owner, 'Owner EQ', 'headphones'), 201, 'data');

        $response = $this->authenticatedRequest('PUT', '/api/user/eq-profiles/' . $created['data']['id'], $intruder, [
            'name' => 'Hacked',
        ]);

        $this->assertSame(200, $response->getStatusCode(), 'update has no ownership check (bug).');
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

    public function testDeleteDoesNotEnforceOwnership(): void
    {
        // BUG PIN: delete() passes only the profileId — no ownership check.
        $owner = $this->createTestUser();
        $intruder = $this->createTestUser();
        $created = $this->assertJsonResponse($this->createProfile($owner, 'Owner EQ', 'headphones'), 201, 'data');

        $response = $this->authenticatedRequest('DELETE', '/api/user/eq-profiles/' . $created['data']['id'], $intruder);

        $this->assertSame(200, $response->getStatusCode(), 'delete has no ownership check (bug).');
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

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createProfile(User $user, string $name, string $icon, ?string $deviceId = null)
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
