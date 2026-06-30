<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model\ValueObject;

use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class ChainIdTest extends TestCase
{
    public function testGenerateCreatesRandomId(): void
    {
        $id1 = ChainId::generate();
        $id2 = ChainId::generate();

        $this->assertNotSame($id1->toString(), $id2->toString());
    }

    public function testFromUuid(): void
    {
        $uuid = Uuid::v4();
        $id = ChainId::fromUuid($uuid);

        $this->assertSame($uuid, $id->getUuid());
    }

    public function testFromString(): void
    {
        $uuid = Uuid::v4();
        $id = ChainId::fromString($uuid->toString());

        $this->assertSame($uuid->toString(), $id->toString());
    }

    public function testEquals(): void
    {
        $uuid = Uuid::v4();
        $id1 = ChainId::fromUuid($uuid);
        $id2 = ChainId::fromUuid($uuid);
        $id3 = ChainId::generate();

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    public function testToString(): void
    {
        $id = ChainId::generate();

        $this->assertSame($id->getUuid()->toString(), $id->toString());
        $this->assertSame($id->getUuid()->toString(), (string) $id);
    }

    public function testJsonSerialize(): void
    {
        $id = ChainId::generate();

        $this->assertSame($id->toString(), $id->jsonSerialize());
    }
}
