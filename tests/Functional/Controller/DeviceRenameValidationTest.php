<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DeviceRenameValidationTest extends TestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function names(): iterable
    {
        yield 'whitespace is rejected' => [" \t\n ", 422, 'Original Device'];
        yield 'valid spacing is preserved' => [' Kitchen Speaker ', 200, ' Kitchen Speaker '];
    }

    #[DataProvider('names')]
    public function testRenameValidatesNameBeforePersisting(string $name, int $status, string $expectedName): void
    {
        $user = $this->createTestUser();
        $deviceId = Uuid::generate()->toString();
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/devices', $user, [
            'deviceId' => $deviceId,
            'name' => 'Original Device',
        ]), 200);

        $response = $this->authenticatedRequest('PUT', '/api/devices/' . $deviceId, $user, ['name' => $name]);

        $this->assertJsonResponse($response, $status);
        $this->entityManager->clear();
        $devices = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/devices', $user), 200, 'data')['data'];
        $this->assertCount(1, $devices);
        $this->assertSame($deviceId, $devices[0]['deviceId']);
        $this->assertSame($expectedName, $devices[0]['name']);
    }
}
