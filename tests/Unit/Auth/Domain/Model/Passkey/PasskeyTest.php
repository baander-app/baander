<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model;

use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\Passkey\PasskeyState;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PasskeyTest extends TestCase
{
    public function testCreate(): void
    {
        $passkey = Passkey::create(
            id: Uuid::v4(),
            name: 'My Key',
            credentialId: 'cred-123',
            data: ['key' => 'value'],
            counter: 0,
        );

        $this->assertSame('My Key', $passkey->getName());
        $this->assertSame('cred-123', $passkey->getCredentialId());
        $this->assertSame(['key' => 'value'], $passkey->getData());
        $this->assertSame(0, $passkey->getCounter());
        $this->assertNull($passkey->getLastUsedAt());
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Passkey::create(Uuid::v4(), '', 'cred-id', [], 0);
    }

    public function testCreateThrowsOnEmptyCredentialId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Passkey::create(Uuid::v4(), 'Name', '', [], 0);
    }

    public function testReconstituteWithLastUsedAt(): void
    {
        $usedAt = new \DateTimeImmutable('-1 hour');

        $passkey = Passkey::reconstitute(new PasskeyState(
            id: Uuid::v4(),
            name: 'Key',
            credentialId: 'cred-id',
            data: [],
            counter: 5,
            createdAt: new \DateTimeImmutable('-2 hours'),
            updatedAt: $usedAt,
            lastUsedAt: $usedAt,
        ));

        $this->assertEquals($usedAt, $passkey->getLastUsedAt());
    }

    public function testReconstituteWithoutLastUsedAt(): void
    {
        $passkey = Passkey::reconstitute(new PasskeyState(
            id: Uuid::v4(),
            name: 'Key',
            credentialId: 'cred-id',
            data: [],
            counter: 0,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));

        $this->assertNull($passkey->getLastUsedAt());
    }

    public function testMarkUsed(): void
    {
        $passkey = Passkey::create(Uuid::v4(), 'Key', 'cred-id', [], 0);
        $this->assertNull($passkey->getLastUsedAt());

        $passkey->markUsed();

        $this->assertNotNull($passkey->getLastUsedAt());
    }

    public function testUpdateCounter(): void
    {
        $passkey = Passkey::create(Uuid::v4(), 'Key', 'cred-id', [], 0);

        $passkey->updateCounter(42);

        $this->assertSame(42, $passkey->getCounter());
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $passkey = Passkey::create(Uuid::v4(), 'Key', 'cred-id', [], 0);

        $this->assertInstanceOf(Uuid::class, $passkey->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $passkey->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $passkey->getUpdatedAt());
    }
}
