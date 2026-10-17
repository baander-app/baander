<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\LoginBlock;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Tests\Functional\TestCase;

final class PublicIdentityTest extends TestCase
{
    public function testCurrentUserAndClientResourcesContainPublicIdsWithoutInternalIds(): void
    {
        $user = $this->createTestUser();
        $me = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/auth/me', $user), 200)['data'];

        self::assertSame($user->getPublicId()->toString(), $me['publicId']);
        self::assertArrayNotHasKey('uuid', $me);
        self::assertArrayNotHasKey('id', $me);
        self::assertStringNotContainsString($user->getId()->toString(), json_encode($me, JSON_THROW_ON_ERROR));
        $client = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/oauth/clients/', $user, ['name' => 'Public identity']), 201)['data'];
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{21}$/D', $client['publicId']);
        self::assertArrayNotHasKey('uuid', $client);
        self::assertArrayNotHasKey('id', $client);
    }

    public function testAnAdministratorAddressesUsersByPublicIdAndCannotUseTheirInternalUuid(): void
    {
        $admin = $this->createSuperAdminUser();
        $user = $this->createTestUser(name: 'Original');
        $privatePath = '/api/admin/users/' . $user->getId()->toString();

        $this->assertJsonResponse($this->authenticatedRequest('PATCH', $privatePath, $admin, ['name' => 'Rejected']), 404);
        $this->assertJsonResponse($this->authenticatedRequest('GET', $privatePath . '/settings', $admin), 404);
        $publicPath = '/api/admin/users/' . $user->getPublicId()->toString();
        $updated = $this->assertJsonResponse($this->authenticatedRequest('PATCH', $publicPath, $admin, ['name' => 'Public name']), 200)['data'];
        self::assertSame('Public name', $updated['name']);
        self::assertSame($user->getPublicId()->toString(), $updated['publicId']);
        self::assertArrayNotHasKey('id', $updated);
        $this->assertJsonResponse($this->authenticatedRequest('GET', $publicPath . '/settings', $admin), 200);
    }

    public function testLoginBlockPublicIdentityResolvesAndItsInternalUuidDoesNot(): void
    {
        $admin = $this->createSuperAdminUser();
        $block = LoginBlock::create('192.0.2.17', 'blocked@baander.app', 'filled', 'Test browser');
        $blocks = static::getContainer()->get(LoginBlockRepositoryInterface::class);
        $blocks->save($block);
        $listed = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/admin/login-blocks', $admin), 200)['data'];
        $found = array_values(array_filter($listed, static fn (array $item): bool => $item['publicId'] === $block->getPublicId()->toString()));
        self::assertCount(1, $found);
        self::assertArrayNotHasKey('id', $found[0]);
        self::assertStringNotContainsString($block->getId()->toString(), json_encode($found, JSON_THROW_ON_ERROR));
        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/admin/login-blocks/' . $block->getId()->toString(), $admin), 404);
        self::assertSame(204, $this->authenticatedRequest('DELETE', '/api/admin/login-blocks/' . $block->getPublicId()->toString(), $admin)->getStatusCode());
    }
}
