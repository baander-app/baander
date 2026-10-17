<?php

declare(strict_types=1);

namespace App\Tests\Unit\Favorites\Infrastructure;

use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Favorites\Infrastructure\FavoriteTargetAccess;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class FavoriteTargetAccessTest extends TestCase
{
    /** @return iterable<string, array{FavoriteType}> */
    public static function types(): iterable
    {
        foreach (FavoriteType::cases() as $type) {
            yield $type->value => [$type];
        }
    }

    #[DataProvider('types')]
    public function testEveryOrdinaryUserTargetLookupIsBoundToPublicIdentityAndLibraryGrant(FavoriteType $type): void
    {
        $actor = new Uuid();
        $publicId = new PublicId();
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(false);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->with(
            self::callback(static function (string $sql): bool {
                self::assertStringContainsString('public_id = :public_id', $sql);
                self::assertStringContainsString('access.user_id = :actor', $sql);
                self::assertStringContainsString('access.library_id = album.library_id', $sql);
                return true;
            }),
            ['public_id' => $publicId->toString(), 'actor' => $actor->toString()],
        )->willReturn(false);
        $this->expectException(NotFoundException::class);
        (new FavoriteTargetAccess($connection, $security))->assertVisible($actor, $type, $publicId);
    }

    public function testAdministratorCanAddressAnExistingArtistWithoutCredits(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);
        $publicId = new PublicId();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')
            ->with('SELECT 1 FROM artists WHERE public_id = :public_id', ['public_id' => $publicId->toString()])->willReturn(1);
        (new FavoriteTargetAccess($connection, $security))->assertVisible(new Uuid(), FavoriteType::Artist, $publicId);
    }
}
