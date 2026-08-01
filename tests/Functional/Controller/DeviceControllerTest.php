<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;
use Symfony\Component\Uid\Uuid as SymfonyUuid;

/**
 * Functional tests for the Session/Device management endpoints.
 *
 * Covers the full CRUD surface of DeviceController:
 *   POST   /api/devices           register (upsert)
 *   GET    /api/devices           list the current user's devices
 *   PUT    /api/devices/{id}      rename
 *   DELETE /api/devices/{id}      forget (remove)
 */
final class DeviceControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // POST /api/devices  (register / upsert)
    // ---------------------------------------------------------------

    public function testRegisterRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('POST', '/api/devices', [
            'deviceId' => $this->validDeviceId(),
            'name' => 'Living Room',
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testRegisterCreatesDevice(): void
    {
        $user = $this->createTestUser();
        $deviceId = $this->validDeviceId();

        $response = $this->authenticatedRequest('POST', '/api/devices', $user, [
            'deviceId' => $deviceId,
            'name' => 'Living Room Speaker',
        ]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame('Device registered.', $data['data']['message']);

        // Verify it is persisted and readable through the listing endpoint.
        $listResponse = $this->authenticatedRequest('GET', '/api/devices', $user);
        $list = $this->assertJsonResponse($listResponse, 200, 'data');

        $this->assertCount(1, $list['data']);
        $this->assertSame($deviceId, $list['data'][0]['deviceId']);
        $this->assertSame('Living Room Speaker', $list['data'][0]['name']);
    }

    public function testRegisterWithoutNameDefaultsToDevice(): void
    {
        $user = $this->createTestUser();

        $this->authenticatedRequest('POST', '/api/devices', $user, [
            'deviceId' => $this->validDeviceId(),
        ]);

        $list = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/devices', $user),
            200,
            'data',
        );

        $this->assertCount(1, $list['data']);
        $this->assertSame('Device', $list['data'][0]['name']);
    }

    public function testRegisterUpsertsExistingDevice(): void
    {
        $user = $this->createTestUser();
        $deviceId = $this->validDeviceId();

        // First registration creates the device.
        $this->authenticatedRequest('POST', '/api/devices', $user, [
            'deviceId' => $deviceId,
            'name' => 'First Name',
        ]);

        // Second registration with the same deviceId must upsert, not duplicate.
        $this->authenticatedRequest('POST', '/api/devices', $user, [
            'deviceId' => $deviceId,
            'name' => 'Second Name',
        ]);

        $list = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/devices', $user),
            200,
            'data',
        );

        $this->assertCount(1, $list['data'], 'Upsert must not create a duplicate device.');
        $this->assertSame($deviceId, $list['data'][0]['deviceId']);
        $this->assertSame('Second Name', $list['data'][0]['name'], 'Upsert must adopt the new name.');
    }

    public function testRegisterRequiresDeviceId(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/devices', $user, [
            'name' => 'Name Only',
        ]);

        $this->assertJsonResponse($response, 422);
    }

    public function testRegisterWithEmptyDeviceIdFails(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/devices', $user, [
            'deviceId' => '',
            'name' => 'Empty Id',
        ]);

        $this->assertJsonResponse($response, 422);
    }

    public function testRegisterWithInvalidDeviceIdFails(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/devices', $user, [
            'deviceId' => 'not-a-uuid',
            'name' => 'Bad Id',
        ]);

        $this->assertJsonResponse($response, 422);
    }

    // ---------------------------------------------------------------
    // GET /api/devices  (list)
    // ---------------------------------------------------------------

    public function testListRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/devices');

        $this->assertJsonResponse($response, 401);
    }

    public function testListReturnsOnlyTheUsersOwnDevices(): void
    {
        $userA = $this->createTestUser('owner-a@example.com');
        $userB = $this->createTestUser('owner-b@example.com');

        $this->authenticatedRequest('POST', '/api/devices', $userA, [
            'deviceId' => $this->validDeviceId(),
            'name' => 'A1',
        ]);
        $this->authenticatedRequest('POST', '/api/devices', $userA, [
            'deviceId' => $this->validDeviceId(),
            'name' => 'A2',
        ]);
        $this->authenticatedRequest('POST', '/api/devices', $userB, [
            'deviceId' => $this->validDeviceId(),
            'name' => 'B1',
        ]);

        $listA = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/devices', $userA),
            200,
            'data',
        );
        $listB = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/devices', $userB),
            200,
            'data',
        );

        $this->assertCount(2, $listA['data'], 'User A must only see their own devices.');
        $this->assertSame(['A1', 'A2'], array_column($listA['data'], 'name'));
        $this->assertCount(1, $listB['data'], 'User B must only see their own devices.');
        $this->assertSame(['B1'], array_column($listB['data'], 'name'));
    }

    public function testListReturnsEmptyArrayWhenUserHasNoDevices(): void
    {
        $user = $this->createTestUser();

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/devices', $user),
            200,
            'data',
        );

        $this->assertSame([], $data['data']);
    }

    // ---------------------------------------------------------------
    // PUT /api/devices/{deviceId}  (rename)
    // ---------------------------------------------------------------

    public function testRenameRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('PUT', '/api/devices/' . $this->validDeviceId(), [
            'name' => 'New Name',
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testRenameUpdatesDeviceName(): void
    {
        $user = $this->createTestUser();
        $deviceId = $this->validDeviceId();

        $this->authenticatedRequest('POST', '/api/devices', $user, [
            'deviceId' => $deviceId,
            'name' => 'Original Name',
        ]);

        $response = $this->authenticatedRequest('PUT', '/api/devices/' . $deviceId, $user, [
            'name' => 'Renamed Device',
        ]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame('Device renamed.', $data['data']['message']);

        $list = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/devices', $user),
            200,
            'data',
        );
        $this->assertSame('Renamed Device', $list['data'][0]['name']);
    }

    public function testRenameWithBlankNameFailsValidation(): void
    {
        $user = $this->createTestUser();
        $deviceId = $this->validDeviceId();

        $this->authenticatedRequest('POST', '/api/devices', $user, [
            'deviceId' => $deviceId,
            'name' => 'Original Name',
        ]);

        $response = $this->authenticatedRequest('PUT', '/api/devices/' . $deviceId, $user, [
            'name' => '',
        ]);

        $this->assertJsonResponse($response, 422);
    }

    public function testRenameWithInvalidDeviceIdReturnsBadRequest(): void
    {
        $user = $this->createTestUser();

        // Valid body so payload validation does not preempt the path-UUID check.
        $response = $this->authenticatedRequest('PUT', '/api/devices/not-a-uuid', $user, [
            'name' => 'Whatever',
        ]);

        $this->assertJsonResponse($response, 400);
    }

    public function testRenameNonExistentDeviceSurfacesServerError(): void
    {
        $user = $this->createTestUser();

        // renameDevice() throws a RuntimeException for an unknown device; the
        // global ExceptionSubscriber maps non-HTTP exceptions to 500. This pins
        // the current behaviour (rename is NOT idempotent, unlike forget).
        $response = $this->authenticatedRequest('PUT', '/api/devices/' . $this->validDeviceId(), $user, [
            'name' => 'Ghost Device',
        ]);

        $this->assertSame(500, $response->getStatusCode(), $response->getContent());
    }

    // ---------------------------------------------------------------
    // DELETE /api/devices/{deviceId}  (forget)
    // ---------------------------------------------------------------

    public function testForgetRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('DELETE', '/api/devices/' . $this->validDeviceId());

        $this->assertJsonResponse($response, 401);
    }

    public function testForgetRemovesDevice(): void
    {
        $user = $this->createTestUser();
        $deviceId = $this->validDeviceId();

        $this->authenticatedRequest('POST', '/api/devices', $user, [
            'deviceId' => $deviceId,
            'name' => 'To Forget',
        ]);

        $response = $this->authenticatedRequest('DELETE', '/api/devices/' . $deviceId, $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame('Device forgotten.', $data['data']['message']);

        $list = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/devices', $user),
            200,
            'data',
        );
        $this->assertSame([], $list['data'], 'Forgotten device must no longer appear in the list.');
    }

    public function testForgetWithInvalidDeviceIdReturnsBadRequest(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('DELETE', '/api/devices/not-a-uuid', $user);

        $this->assertJsonResponse($response, 400);
    }

    public function testForgetNonExistentDeviceIsIdempotent(): void
    {
        $user = $this->createTestUser();

        // Forgetting a device that was never registered succeeds silently,
        // mirroring an idempotent delete contract (unlike rename).
        $response = $this->authenticatedRequest('DELETE', '/api/devices/' . $this->validDeviceId(), $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame('Device forgotten.', $data['data']['message']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function validDeviceId(): string
    {
        return SymfonyUuid::v4()->toRfc4122();
    }
}
