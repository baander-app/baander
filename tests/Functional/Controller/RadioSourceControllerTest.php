<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Tests\Functional\TestCase;

final class RadioSourceControllerTest extends TestCase
{
    public function testCreateIsForbiddenForNonAdmin(): void
    {
        $name = 'Listener source ' . bin2hex(random_bytes(4));

        $response = $this->authenticatedRequest('POST', '/api/radio/sources', $this->createTestUser(), [
            'name' => $name,
            'type' => 'iprd',
            'syncUrl' => 'https://radio.baander.app/catalog',
        ]);

        $this->assertJsonResponse($response, 403);
        self::assertNotContains($name, array_column($this->sources()->listSources(), 'name'));
    }

    public function testAdminCreatesSource(): void
    {
        $name = 'Admin source ' . bin2hex(random_bytes(4));

        $response = $this->authenticatedRequest('POST', '/api/radio/sources', $this->createAdminUser(), [
            'name' => $name,
            'type' => 'iprd',
            'syncUrl' => 'https://radio.baander.app/catalog',
        ]);

        $data = $this->assertJsonResponse($response, 201);
        self::assertSame($name, $data['name']);
        self::assertContains($name, array_column($this->sources()->listSources(), 'name'));
    }

    private function sources(): RadioSourcePortInterface
    {
        return static::getContainer()->get(RadioSourcePortInterface::class);
    }
}
