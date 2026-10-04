<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\LoginBlock;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminLoginBlockControllerTest extends TestCase
{
    public function testMalformedIdentifierReturnsNotFoundWithoutDeletingBlocks(): void
    {
        $repository = static::getContainer()->get(LoginBlockRepositoryInterface::class);
        $block = LoginBlock::create('192.0.2.1', 'blocked@baander.app', 'bot', 'test');
        $repository->save($block);
        $before = $repository->countRecent();

        $response = $this->authenticatedRequest('DELETE', '/api/admin/login-blocks/not-a-uuid', $this->createSuperAdminUser());

        $this->assertJsonResponse($response, 404);
        self::assertSame($before, $repository->countRecent());
    }

    /** @return iterable<string, array{string, int}> */
    public static function unauthorizedRoles(): iterable
    {
        yield 'anonymous' => ['anonymous', 401];
        yield 'ordinary user' => ['user', 403];
        yield 'administrator' => ['admin', 403];
    }

    #[DataProvider('unauthorizedRoles')]
    public function testDeletionRequiresSuperAdmin(string $role, int $status): void
    {
        $repository = static::getContainer()->get(LoginBlockRepositoryInterface::class);
        $block = LoginBlock::create('192.0.2.1', 'blocked@baander.app', 'bot', 'test');
        $repository->save($block);
        $before = $repository->countRecent();
        $uri = '/api/admin/login-blocks/' . $block->getId()->toString();

        $response = match ($role) {
            'anonymous' => $this->anonymousRequest('DELETE', $uri),
            'admin' => $this->authenticatedRequest('DELETE', $uri, $this->createAdminUser()),
            default => $this->authenticatedRequest('DELETE', $uri, $this->createTestUser()),
        };

        $this->assertJsonResponse($response, $status);
        self::assertSame($before, $repository->countRecent());
    }

    public function testSuperAdminDeletesOnlyTheRequestedBlock(): void
    {
        $repository = static::getContainer()->get(LoginBlockRepositoryInterface::class);
        $block = LoginBlock::create('192.0.2.1', 'blocked@baander.app', 'bot', 'test');
        $other = LoginBlock::create('192.0.2.2', 'other@baander.app', 'bot', 'test');
        $repository->save($block);
        $repository->save($other);

        $response = $this->authenticatedRequest('DELETE', '/api/admin/login-blocks/' . $block->getId()->toString(), $this->createSuperAdminUser());

        self::assertSame(204, $response->getStatusCode());
        $ids = array_map(static fn (LoginBlock $item): string => $item->getId()->toString(), $repository->findRecent());
        self::assertNotContains($block->getId()->toString(), $ids);
        self::assertContains($other->getId()->toString(), $ids);
    }
}
