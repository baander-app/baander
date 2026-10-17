<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Infrastructure;

use App\Activity\Infrastructure\Doctrine\ActivityIdentityProjection;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ActivityIdentityProjectionTest extends TestCase
{
    public function testMembershipScopeUsesOnlyTheNamedUsersLibraryIds(): void
    {
        $user = new Uuid();
        $library = new Uuid();
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchFirstColumn')
            ->with('SELECT library_id FROM user_library_access WHERE user_id = :userId', ['userId' => $user->toString()])
            ->willReturn([$library->toString()]);
        $projection = new ActivityIdentityProjection($connection);

        $scope = $projection->readScope($user, false);

        self::assertTrue($scope->allows($library));
        self::assertFalse($scope->allows(new Uuid()));
        self::assertTrue($projection->readScope($user, true)->isUnrestricted());
    }

    public function testIdentityReadsReturnPersistedPublicIdsAndMissingArtistIsNullable(): void
    {
        $user = new Uuid();
        $artist = new Uuid();
        $userPublic = new PublicId();
        $artistPublic = new PublicId();
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(3))->method('fetchOne')->willReturnCallback(static function (string $sql, array $parameters) use ($user, $artist, $userPublic, $artistPublic): string|false {
            if ($sql === 'SELECT public_id FROM users WHERE id = :id') {
                self::assertSame(['id' => $user->toString()], $parameters);

                return $userPublic->toString();
            }
            self::assertSame('SELECT public_id FROM artists WHERE id = :id', $sql);

            return $parameters['id'] === $artist->toString() ? $artistPublic->toString() : false;
        });
        $projection = new ActivityIdentityProjection($connection);

        self::assertEquals($userPublic, $projection->userPublicId($user));
        self::assertEquals($artistPublic, $projection->artistPublicId($artist));
        self::assertNull($projection->artistPublicId(new Uuid()));
    }

    public function testMissingUserCannotBeProjectedAsAnInternalUuid(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $this->expectException(NotFoundException::class);

        (new ActivityIdentityProjection($connection))->userPublicId(new Uuid());
    }
}
