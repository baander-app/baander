<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure;

use App\QoL\Infrastructure\QoLJobIdentity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class QoLJobIdentityTest extends TestCase
{
    public function testBatchProjectionReturnsPublicIdentityAndDeduplicatesInternalKeys(): void
    {
        $id = (new Uuid())->toString();
        $publicId = (new PublicId())->toString();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllKeyValue')
            ->with('SELECT id, public_id FROM transcode_jobs WHERE id IN (:ids)', ['ids' => [$id]], ['ids' => ArrayParameterType::STRING])
            ->willReturn([$id => $publicId]);
        self::assertSame([$id => $publicId], (new QoLJobIdentity($connection))->publicIds([$id, $id]));
    }

    public function testEmptyProjectionDoesNotQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAllKeyValue');
        self::assertSame([], (new QoLJobIdentity($connection))->publicIds([]));
    }

    public function testCorruptUuidPublicIdentityCannotBeExposed(): void
    {
        $id = (new Uuid())->toString();
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllKeyValue')->willReturn([$id => $id]);
        $this->expectException(\InvalidArgumentException::class);
        (new QoLJobIdentity($connection))->publicIds([$id]);
    }
}
