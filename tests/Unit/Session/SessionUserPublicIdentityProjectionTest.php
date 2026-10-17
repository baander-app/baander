<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Session\Infrastructure\Doctrine\SessionUserPublicIdentityProjection;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class SessionUserPublicIdentityProjectionTest extends TestCase
{
    public function testOwnerLookupReturnsTheIndependentlyPersistedPublicId(): void
    {
        $owner = new Uuid();
        $publicId = new PublicId();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')
            ->with('SELECT public_id FROM users WHERE id = :id', ['id' => $owner->toString()])
            ->willReturn($publicId->toString());

        $resolved = (new SessionUserPublicIdentityProjection($connection))->publicIdByUserId($owner);

        self::assertTrue($resolved->equals($publicId));
        self::assertNotSame($owner->toString(), $resolved->toString());
    }

    public function testMissingOwnerFailsWithoutExposingItsInternalIdentity(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Session owner not found.');

        (new SessionUserPublicIdentityProjection($connection))->publicIdByUserId(new Uuid());
    }
}
