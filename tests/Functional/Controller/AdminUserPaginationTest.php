<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminUserPaginationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidQueries(): iterable
    {
        foreach (['limit' => ['-1', '0', '101', 'abc', '1.5', '1e2', '', '999999999999999999999999'], 'offset' => ['-1', 'abc', '1.5', '', '999999999999999999999999']] as $field => $values) {
            foreach ($values as $value) {
                yield $field . '=' . $value => [$field . '=' . $value];
            }
            yield $field . ' array' => [$field . '[]=1'];
        }
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidPaginationReturnsBadRequest(string $query): void
    {
        $response = $this->authenticatedRequest('GET', '/api/admin/users?' . $query, $this->createAdminUser());

        $data = $this->assertJsonResponse($response, 400);
        self::assertArrayHasKey('error', $data);
        self::assertSame(400, $data['error']['code']);
    }

    public function testPaginationPreservesRoleAndDisabledFilters(): void
    {
        $admin = $this->createAdminUser();
        $disabledAdmin = $this->createAdminUser();
        $disabledAdmin->disable();
        $this->userRepository->save($disabledAdmin);
        $ordinaryUser = $this->createTestUser();
        $ordinaryUser->disable();
        $this->userRepository->save($ordinaryUser);

        $response = $this->authenticatedRequest('GET', '/api/admin/users?role=ROLE_ADMIN&disabled=true&limit=1&offset=0', $admin);
        $data = $this->assertJsonResponse($response, 200);
        self::assertSame(['total' => 1, 'limit' => 1, 'offset' => 0], $data['meta']);
        self::assertSame([$disabledAdmin->getId()->toString()], array_column($data['data'], 'id'));

        $response = $this->authenticatedRequest('GET', '/api/admin/users?role=ROLE_ADMIN&disabled=true&limit=100&offset=1', $admin);
        $data = $this->assertJsonResponse($response, 200);
        self::assertSame(['total' => 1, 'limit' => 100, 'offset' => 1], $data['meta']);
        self::assertSame([], $data['data']);
    }

    public function testDefaultPagination(): void
    {
        $admin = $this->createAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/users', $admin);

        $data = $this->assertJsonResponse($response, 200);
        self::assertSame(50, $data['meta']['limit']);
        self::assertSame(0, $data['meta']['offset']);
        self::assertContains($admin->getId()->toString(), array_column($data['data'], 'id'));
    }
}
