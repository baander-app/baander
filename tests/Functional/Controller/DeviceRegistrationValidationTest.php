<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DeviceRegistrationValidationTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRegistrations(): iterable
    {
        $deviceId = '00000000-0000-7000-8000-000000000001';

        yield 'missing device ID' => [['name' => 'Device']];
        yield 'non-string device ID' => [['deviceId' => 42, 'name' => 'Device']];
        yield 'blank device ID' => [['deviceId' => '', 'name' => 'Device']];
        yield 'invalid UUID' => [['deviceId' => 'not-a-uuid', 'name' => 'Device']];
        yield 'non-string name' => [['deviceId' => $deviceId, 'name' => 42]];
        yield 'empty name' => [['deviceId' => $deviceId, 'name' => '']];
        yield 'whitespace-only name' => [['deviceId' => $deviceId, 'name' => " \t\n "]];
        yield 'oversized name' => [['deviceId' => $deviceId, 'name' => str_repeat('a', 256)]];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidRegistrations')]
    public function testInvalidRegistrationReturnsValidationErrorWithoutCreatingDevice(array $payload): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/devices', $user, $payload);

        $this->assertJsonResponse($response, 422);
        $this->entityManager->clear();
        $listed = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/devices', $user), 200, 'data')['data'];
        $this->assertSame([], $listed);
    }

    public function testMalformedJsonReturnsBadRequestWithoutCreatingDevice(): void
    {
        $user = $this->createTestUser();

        $this->client->request('POST', '/api/devices', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BAANDER_TEST_USER_ID' => $user->getId()->toString(),
        ], '{"deviceId":');

        $this->assertJsonResponse($this->client->getResponse(), 400);
        $this->entityManager->clear();
        $listed = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/devices', $user), 200, 'data')['data'];
        $this->assertSame([], $listed);
    }

    public function testOmittedNameDefaultsToDevice(): void
    {
        $user = $this->createTestUser();
        $deviceId = Uuid::generate()->toString();

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/devices', $user, ['deviceId' => $deviceId]), 200);

        $this->entityManager->clear();
        $listed = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/devices', $user), 200, 'data')['data'];
        $this->assertCount(1, $listed);
        $this->assertSame($deviceId, $listed[0]['deviceId']);
        $this->assertSame('Device', $listed[0]['name']);
    }

    public function testInvalidUpsertNameLeavesExistingDeviceUnchanged(): void
    {
        $user = $this->createTestUser();
        $deviceId = Uuid::generate()->toString();
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/devices', $user, [
            'deviceId' => $deviceId,
            'name' => 'Original Device',
        ]), 200);
        $original = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/devices', $user), 200, 'data')['data'];

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/devices', $user, [
            'deviceId' => $deviceId,
            'name' => " \t\n ",
        ]), 422);

        $this->entityManager->clear();
        $stored = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/devices', $user), 200, 'data')['data'];
        $this->assertSame($original, $stored);
    }
}
