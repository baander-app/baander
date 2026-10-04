<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminUserValidationTest extends TestCase
{
    public function testWhitespaceOnlyNameCannotCreateAUser(): void
    {
        $admin = $this->createSuperAdminUser();
        $originalCount = $this->userRepository->count();

        $response = $this->authenticatedRequest('POST', '/api/admin/users', $admin, [
            'email' => 'rejected@baander.app',
            'password' => 'securePassword123',
            'name' => " \t\n ",
        ]);

        $this->assertJsonResponse($response, 422);
        $this->entityManager->clear();
        $this->assertSame($originalCount, $this->userRepository->count());
        $this->assertNull($this->userRepository->findByEmail(new Email('rejected@baander.app')));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidUpdates(): iterable
    {
        yield 'empty email' => [['email' => '', 'name' => 'Attempted Change']];
        yield 'blank email' => [['email' => " \t ", 'name' => 'Attempted Change']];
        yield 'blank name' => [['name' => " \t\n ", 'email' => 'changed@baander.app']];
    }

    /** @param array<string, string> $payload */
    #[DataProvider('invalidUpdates')]
    public function testInvalidPartialUpdateLeavesStoredUserUnchanged(array $payload): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createTestUser('original@baander.app', 'Original Name');
        $target = $this->storedUser($target->getId());
        $originalPassword = $target->getPassword();
        $originalUpdatedAt = $target->getUpdatedAt();

        $response = $this->authenticatedRequest('PATCH', '/api/admin/users/' . $target->getId()->toString(), $admin, $payload);

        $this->assertJsonResponse($response, 422);
        $stored = $this->storedUser($target->getId());
        $this->assertSame('original@baander.app', $stored->getEmail());
        $this->assertSame('Original Name', $stored->getName());
        $this->assertSame($originalPassword, $stored->getPassword());
        $this->assertEquals($originalUpdatedAt, $stored->getUpdatedAt());
    }

    /** @return iterable<string, array{array<string, ?string>, string, string}> */
    public static function acceptedPartialUpdates(): iterable
    {
        yield 'omitted email' => [['name' => ' Updated Name '], ' Updated Name ', 'original@baander.app'];
        yield 'null email' => [['name' => ' Updated Name ', 'email' => null], ' Updated Name ', 'original@baander.app'];
        yield 'omitted name' => [['email' => 'changed@baander.app'], 'Original Name', 'changed@baander.app'];
        yield 'null name' => [['name' => null, 'email' => 'changed@baander.app'], 'Original Name', 'changed@baander.app'];
        yield 'both null' => [['name' => null, 'email' => null], 'Original Name', 'original@baander.app'];
    }

    /** @param array<string, ?string> $payload */
    #[DataProvider('acceptedPartialUpdates')]
    public function testAcceptedPartialUpdatePreservesOmittedAndNullFields(array $payload, string $expectedName, string $expectedEmail): void
    {
        $admin = $this->createSuperAdminUser();
        $target = $this->createTestUser('original@baander.app', 'Original Name');

        $response = $this->authenticatedRequest('PATCH', '/api/admin/users/' . $target->getId()->toString(), $admin, $payload);

        $data = $this->assertJsonResponse($response, 200, 'data')['data'];
        $this->assertSame($expectedName, $data['name']);
        $this->assertSame($expectedEmail, $data['email']);
        $stored = $this->storedUser($target->getId());
        $this->assertSame($expectedName, $stored->getName());
        $this->assertSame($expectedEmail, $stored->getEmail());
    }

    public function testValidCreatePreservesWhitespaceAroundName(): void
    {
        $admin = $this->createSuperAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/admin/users', $admin, [
            'email' => 'created@baander.app',
            'password' => 'securePassword123',
            'name' => ' New User ',
        ]);

        $data = $this->assertJsonResponse($response, 201, 'data')['data'];
        $this->assertSame(' New User ', $data['name']);
        $stored = $this->storedUser(Uuid::fromString($data['id']));
        $this->assertSame(' New User ', $stored->getName());
        $this->assertSame('created@baander.app', $stored->getEmail());
    }

    private function storedUser(Uuid $id): User
    {
        $this->entityManager->clear();
        $user = $this->userRepository->findByUuid($id);
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }
}
