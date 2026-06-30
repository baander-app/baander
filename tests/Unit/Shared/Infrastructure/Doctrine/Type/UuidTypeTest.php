<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Type\UuidType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use PHPUnit\Framework\TestCase;

final class UuidTypeTest extends TestCase
{
    private UuidType $type;
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new UuidType();
        $this->platform = $this->createMock(AbstractPlatform::class);
    }

    public function testGetNameReturnsUuid(): void
    {
        $this->assertSame('uuid', $this->type->getName());
    }

    public function testGetSQLDeclarationDelegatesToPlatform(): void
    {
        $column = ['name' => 'id'];
        $this->platform
            ->expects($this->once())
            ->method('getGuidTypeDeclarationSQL')
            ->with($column)
            ->willReturn('UUID');

        $result = $this->type->getSQLDeclaration($column, $this->platform);

        $this->assertSame('UUID', $result);
    }

    public function testConvertToPHPValueReturnsNullForNullInput(): void
    {
        $result = $this->type->convertToPHPValue(null, $this->platform);

        $this->assertNull($result);
    }

    public function testConvertToPHPValueReturnsUuidForString(): void
    {
        $uuidString = '01950a7a-7a7a-7000-8000-000000000001';
        $result = $this->type->convertToPHPValue($uuidString, $this->platform);

        $this->assertInstanceOf(Uuid::class, $result);
        $this->assertSame($uuidString, $result->toString());
    }

    public function testConvertToPHPValueReturnsSameUuidInstanceUnchanged(): void
    {
        $uuid = Uuid::fromString('01950a7a-7a7a-7000-8000-000000000001');
        $result = $this->type->convertToPHPValue($uuid, $this->platform);

        $this->assertSame($uuid, $result);
    }

    public function testConvertToDatabaseValueReturnsNullForNullInput(): void
    {
        $result = $this->type->convertToDatabaseValue(null, $this->platform);

        $this->assertNull($result);
    }

    public function testConvertToDatabaseValueReturnsStringForUuidInstance(): void
    {
        $uuidString = '01950a7a-7a7a-7000-8000-000000000001';
        $uuid = Uuid::fromString($uuidString);

        $result = $this->type->convertToDatabaseValue($uuid, $this->platform);

        $this->assertSame($uuidString, $result);
    }

    public function testConvertToDatabaseValueThrowsInvalidArgumentExceptionForInt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Expected App\\\\Shared\\\\Domain\\\\Model\\\\Uuid or null, got int/');

        $this->type->convertToDatabaseValue(42, $this->platform);
    }
}
