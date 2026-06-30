<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Doctrine\Type\PublicIdType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use PHPUnit\Framework\TestCase;

final class PublicIdTypeTest extends TestCase
{
    private PublicIdType $type;
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new PublicIdType();
        $this->platform = $this->createMock(AbstractPlatform::class);
    }

    public function testGetNameReturnsPublicId(): void
    {
        $this->assertSame('public_id', $this->type->getName());
    }

    public function testConvertToPHPValueReturnsNullForNullInput(): void
    {
        $result = $this->type->convertToPHPValue(null, $this->platform);

        $this->assertNull($result);
    }

    public function testConvertToPHPValueReturnsPublicIdForString(): void
    {
        $idString = 'V1StGXR8_Z5jdHi6B-myT';
        $result = $this->type->convertToPHPValue($idString, $this->platform);

        $this->assertInstanceOf(PublicId::class, $result);
        $this->assertSame($idString, $result->toString());
    }

    public function testConvertToPHPValueReturnsSamePublicIdInstanceUnchanged(): void
    {
        $publicId = PublicId::fromString('V1StGXR8_Z5jdHi6B-myT');
        $result = $this->type->convertToPHPValue($publicId, $this->platform);

        $this->assertSame($publicId, $result);
    }

    public function testConvertToDatabaseValueReturnsNullForNullInput(): void
    {
        $result = $this->type->convertToDatabaseValue(null, $this->platform);

        $this->assertNull($result);
    }

    public function testConvertToDatabaseValueReturnsStringForPublicIdInstance(): void
    {
        $idString = 'V1StGXR8_Z5jdHi6B-myT';
        $publicId = PublicId::fromString($idString);

        $result = $this->type->convertToDatabaseValue($publicId, $this->platform);

        $this->assertSame($idString, $result);
    }

    public function testConvertToDatabaseValueThrowsInvalidArgumentExceptionForInt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Expected App\\\\Shared\\\\Domain\\\\Model\\\\PublicId or null, got int/');

        $this->type->convertToDatabaseValue(42, $this->platform);
    }
}
