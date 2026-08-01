<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Controller\Passkey;

use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\Passkey\PasskeyState;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Interface\Controller\Passkey\PasskeyController;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class PasskeyControllerTest extends TestCase
{
    private Security&MockObject $security;
    private PasskeyRepositoryInterface&MockObject $passkeyRepository;
    private PasskeyController $controller;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->passkeyRepository = $this->createMock(PasskeyRepositoryInterface::class);

        $ref = new \ReflectionClass(PasskeyController::class);
        $this->controller = $ref->newInstanceWithoutConstructor();

        $this->setPrivate($this->controller, 'security', $this->security);
        $this->setPrivate($this->controller, 'passkeyRepository', $this->passkeyRepository);
    }

    public function testListReturnsPasskeysForAuthenticatedUser(): void
    {
        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@example.com',
            password: 'hashed-pw',
        );
        $this->security->method('getUser')->willReturn($user);

        $passkey1 = Passkey::create(Uuid::v4(), 'YubiKey', 'cred-1', ['publicKey' => 'key1'], 0);
        $passkey2 = Passkey::create(Uuid::v4(), 'iPhone', 'cred-2', ['publicKey' => 'key2'], 5);

        $this->passkeyRepository->expects($this->once())
            ->method('forUser')
            ->willReturn([$passkey1, $passkey2]);

        $response = $this->controller->list();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertCount(2, $data['data']);
        $this->assertArrayHasKey('publicId', $data['data'][0]);
        $this->assertArrayHasKey('name', $data['data'][0]);
        $this->assertArrayHasKey('createdAt', $data['data'][0]);
        $this->assertArrayHasKey('lastUsedAt', $data['data'][0]);
    }

    public function testListReturnsEmptyArrayForUserWithNoPasskeys(): void
    {
        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@example.com',
            password: 'hashed-pw',
        );
        $this->security->method('getUser')->willReturn($user);

        $this->passkeyRepository->method('forUser')->willReturn([]);

        $response = $this->controller->list();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame([], $data['data']);
    }

    public function testListReturns401ForUnauthenticatedUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $response = $this->controller->list();

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testListNeverIncludesCredentialIdOrData(): void
    {
        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@example.com',
            password: 'hashed-pw',
        );
        $this->security->method('getUser')->willReturn($user);

        $passkey = Passkey::create(Uuid::v4(), 'Test Key', 'secret-credential-id', ['sensitive' => 'data'], 0);

        $this->passkeyRepository->method('forUser')->willReturn([$passkey]);

        $response = $this->controller->list();
        $content = $response->getContent();

        $this->assertStringNotContainsString('secret-credential-id', $content);
        $this->assertStringNotContainsString('sensitive', $content);
        $this->assertStringNotContainsString('credentialId', $content);
    }

    public function testListOrdersByCreatedAtDescending(): void
    {
        $userId = Uuid::v4();
        $user = new SecurityUser(
            id: $userId->toString(),
            email: 'user@example.com',
            password: 'hashed-pw',
        );
        $this->security->method('getUser')->willReturn($user);

        $older = self::makePasskey(Uuid::v4(), 'Older', 'c1', new DateTimeImmutable('2026-01-01'));
        $newer = self::makePasskey(Uuid::v4(), 'Newer', 'c2', new DateTimeImmutable('2026-05-01'));

        $this->passkeyRepository->method('forUser')->willReturn([$older, $newer]);

        $response = $this->controller->list();
        $data = json_decode($response->getContent(), true);

        $this->assertSame('Newer', $data['data'][0]['name']);
        $this->assertSame('Older', $data['data'][1]['name']);
    }

    private static function makePasskey(Uuid $id, string $name, string $credentialId, DateTimeImmutable $createdAt): Passkey
    {
        $state = new PasskeyState(
            id: $id,
            name: $name,
            credentialId: $credentialId,
            data: [],
            counter: 0,
            createdAt: $createdAt,
            updatedAt: $createdAt,
        );

        return Passkey::reconstitute($state);
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }
}
