<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminUserFilterValidationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidFilters(): iterable
    {
        yield 'unknown role' => ['role=ROLE_UNKNOWN'];
        yield 'empty role' => ['role='];
        yield 'role array' => ['role[]=ROLE_ADMIN'];
        yield 'invalid disabled' => ['disabled=invalid'];
        yield 'empty disabled' => ['disabled='];
        yield 'numeric disabled' => ['disabled=2'];
        yield 'disabled array' => ['disabled[]=true'];
    }

    #[DataProvider('invalidFilters')]
    public function testInvalidFilterReturnsBadRequest(string $query): void
    {
        $response = $this->authenticatedRequest('GET', '/api/admin/users?' . $query, $this->createAdminUser());

        $data = $this->assertJsonResponse($response, 400);
        self::assertArrayHasKey('error', $data);
        self::assertSame(400, $data['error']['code']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function disabledFilters(): iterable
    {
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield 'one' => ['1', true];
        yield 'zero' => ['0', false];
    }

    #[DataProvider('disabledFilters')]
    public function testValidDisabledFilterSelectsMatchingUsers(string $value, bool $disabled): void
    {
        $admin = $this->createAdminUser();
        $target = $this->createAdminUser();
        $target->disable();
        $this->userRepository->save($target);

        $response = $this->authenticatedRequest('GET', '/api/admin/users?role=ROLE_ADMIN&disabled=' . $value, $admin);
        $data = $this->assertJsonResponse($response, 200);
        self::assertSame(1, $data['meta']['total']);
        self::assertSame([$disabled ? $target->getId()->toString() : $admin->getId()->toString()], array_column($data['data'], 'id'));
    }
}
