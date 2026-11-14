<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure\Doctrine\ImportIdentity;

use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;

final class ImportIdentityLockTest extends TestCase
{
    public function testTakesEachDistinctKeyOncePerStatementInByteOrder(): void
    {
        $sent = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('executeQuery')->willReturnCallback(function (string $sql, array $parameters) use (&$sent): Result {
            $sent[] = [$sql, $parameters];

            return $this->createStub(Result::class);
        });

        ImportIdentityLock::acquire($connection, ['artist-name:b', 'genre:rock', 'artist-mbid:x', 'artist-name:B', 'artist-name:b']);

        self::assertSame(
            ['artist-mbid:x', 'artist-name:B', 'artist-name:b', 'genre:rock'],
            array_map(static fn (array $statement): string => $statement[1]['key'], $sent),
        );
        foreach ($sent as [$sql]) {
            self::assertSame('SELECT pg_advisory_xact_lock(hashtextextended(:key, 20261114120000))', $sql);
        }
    }

    public function testRefusesOutsideATransactionWithoutTakingALock(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->never())->method('executeQuery');

        $this->expectException(\LogicException::class);

        ImportIdentityLock::acquire($connection, ['artist-name:Disturbed']);
    }

    public function testArtistKeysNameTheTaggedNameAndTheCanonicalId(): void
    {
        $id = MusicbrainzId::fromString(' 9A709693-B4F8-4DA9-8CC1-038C911A61BE ');
        self::assertNotNull($id);

        self::assertSame('artist-name:Post Malone', ImportIdentityLock::artistNameKey('Post Malone'));
        self::assertSame('artist-mbid:9a709693-b4f8-4da9-8cc1-038c911a61be', ImportIdentityLock::artistMusicbrainzIdKey($id));
    }
}
