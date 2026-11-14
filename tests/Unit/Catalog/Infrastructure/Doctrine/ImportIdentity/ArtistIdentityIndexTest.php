<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure\Doctrine\ImportIdentity;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ArtistIdentityCandidate;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ArtistIdentityIndex;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArtistIdentityIndexTest extends TestCase
{
    private const string ID_A = '5b11f4ce-a62d-471e-81fc-a69a8278c7da';
    private const string ID_B = '0383dadf-2a4e-4d10-a46a-e9e041da8eb3';
    private const string ID_X = '9a709693-b4f8-4da9-8cc1-038c911a61be';

    public function testAnIdNamesTheArtistHoldingItWhateverItsName(): void
    {
        $holder = $this->stored('Headhunterz', self::ID_X, '2026-11-01 10:00:00');
        $index = new ArtistIdentityIndex([$this->stored('HeadHunterz', null, '2026-10-01 10:00:00'), $holder]);

        self::assertSame($holder, $index->resolve(new ArtistIdentity('HeadHunterz', [self::ID_X])));
        self::assertCount(2, $index->artists(), 'No artist is created.');
    }

    public function testTheOldestSameNameArtistWithoutAnIdTakesOnTheId(): void
    {
        $older = $this->stored('Headhunterz', null, '2026-10-01 10:00:00');
        $newer = $this->stored('Headhunterz', null, '2026-11-01 10:00:00');
        $index = new ArtistIdentityIndex([$newer, $older, $this->stored('Headhunterz', self::ID_A, '2026-09-01 10:00:00')]);

        $resolved = $index->resolve(new ArtistIdentity('Headhunterz', [self::ID_X]));

        self::assertSame($older, $resolved);
        self::assertSame(self::ID_X, $older->mbid);
        self::assertNull($newer->mbid);
        self::assertCount(3, $index->artists());
    }

    public function testAnIdNoArtistHoldsOrCanTakeCreatesAnArtistWithNameAndId(): void
    {
        $other = $this->stored('Nirvana', self::ID_A, '2026-10-01 10:00:00');
        $index = new ArtistIdentityIndex([$other]);

        $created = $index->resolve(new ArtistIdentity('Nirvana', [self::ID_B]));

        self::assertNotSame($other, $created);
        self::assertSame(['Nirvana', self::ID_B, null], [$created->name, $created->mbid, $created->createdAt]);
        self::assertSame([$other, $created], $index->artists());
    }

    public function testWithoutAnIdTheOnlySameNameArtistIsTakenEvenWhenItHasAnId(): void
    {
        $only = $this->stored('Nirvana', self::ID_A, '2026-10-01 10:00:00');
        $index = new ArtistIdentityIndex([$only, $this->stored('Nirvana Tribute', null, '2026-09-01 10:00:00')]);

        self::assertSame($only, $index->resolve(new ArtistIdentity('Nirvana')));
        self::assertSame(self::ID_A, $only->mbid);
    }

    public function testWithoutAnIdAmongSameNameArtistsWithIdsTheOldestIsTaken(): void
    {
        $older = $this->stored('Nirvana', self::ID_A, '2026-10-01 10:00:00');
        $newer = $this->stored('Nirvana', self::ID_B, '2026-11-01 10:00:00');
        $index = new ArtistIdentityIndex([$newer, $older]);

        self::assertSame($older, $index->resolve(new ArtistIdentity('Nirvana')));
        self::assertCount(2, $index->artists());
    }

    public function testWithoutAnIdTheOldestSameNameArtistWithoutAnIdIsPreferred(): void
    {
        $withId = $this->stored('Nirvana', self::ID_A, '2026-09-01 10:00:00');
        $olderWithout = $this->stored('Nirvana', null, '2026-10-01 10:00:00');
        $newerWithout = $this->stored('Nirvana', null, '2026-11-01 10:00:00');
        $index = new ArtistIdentityIndex([$newerWithout, $withId, $olderWithout]);

        self::assertSame($olderWithout, $index->resolve(new ArtistIdentity('Nirvana')));
    }

    public function testArtistsCreatedAtTheSameInstantAreOrderedByTheirId(): void
    {
        $lowId = new ArtistIdentityCandidate(Uuid::fromString('0190a000-0000-7000-8000-000000000001'), 'Nirvana', null, new DateTimeImmutable('2026-10-01 10:00:00.123456'));
        $highId = new ArtistIdentityCandidate(Uuid::fromString('0190a000-0000-7000-8000-00000000000a'), 'Nirvana', null, new DateTimeImmutable('2026-10-01 10:00:00.123456'));

        self::assertSame($lowId, (new ArtistIdentityIndex([$highId, $lowId]))->resolve(new ArtistIdentity('Nirvana')));
        self::assertSame($lowId, (new ArtistIdentityIndex([$lowId, $highId]))->resolve(new ArtistIdentity('Nirvana')));
    }

    public function testNamesMatchExactlyWithoutFoldingCaseOrSpace(): void
    {
        $index = new ArtistIdentityIndex([$this->stored('nirvana', null, '2026-10-01 10:00:00'), $this->stored('Nirvana ', null, '2026-10-01 11:00:00')]);

        $created = $index->resolve(new ArtistIdentity('Nirvana'));

        self::assertSame(['Nirvana', null], [$created->name, $created->createdAt]);
        self::assertCount(3, $index->artists());
    }

    public function testSeveralIdsResolveByTheExactName(): void
    {
        $byName = $this->stored('A feat. B', null, '2026-10-01 10:00:00');
        $index = new ArtistIdentityIndex([$this->stored('A', self::ID_A, '2026-09-01 10:00:00'), $byName]);

        self::assertSame($byName, $index->resolve(new ArtistIdentity('A feat. B', [self::ID_A, self::ID_B])));
        self::assertNull($byName->mbid, 'An identity with several IDs gives none to the artist it names.');
    }

    public function testSeveralIdsCreateANameOnlyArtistWhenNoneHasTheName(): void
    {
        $index = new ArtistIdentityIndex([$this->stored('A', self::ID_A, '2026-09-01 10:00:00')]);

        $created = $index->resolve(new ArtistIdentity('A feat. B', [self::ID_A, self::ID_B]));

        self::assertSame(['A feat. B', null], [$created->name, $created->mbid]);
    }

    public function testAMalformedIdResolvesByName(): void
    {
        $byName = $this->stored('Suspekt', null, '2026-10-01 10:00:00');
        $index = new ArtistIdentityIndex([$byName]);

        self::assertSame($byName, $index->resolve(new ArtistIdentity('Suspekt', ['not-a-musicbrainz-id'])));
        self::assertNull($byName->mbid);
    }

    public function testFourteenIdenticalIdentitiesCreateOneArtist(): void
    {
        $index = new ArtistIdentityIndex([]);

        $resolved = array_map(
            static fn (int $track): Uuid => $index->resolve(new ArtistIdentity('Disturbed'))->id,
            range(1, 14),
        );

        self::assertCount(1, array_unique(array_map(static fn (Uuid $id): string => $id->toString(), $resolved)));
        self::assertCount(1, $index->artists());
        self::assertSame('Disturbed', $index->artists()[0]->name);
    }

    public function testAliasesWithOneIdConvergeOnTheArtistNamedAfterTheFirstAlias(): void
    {
        $index = new ArtistIdentityIndex([]);

        $first = $index->resolve(new ArtistIdentity('Headhunterz', [self::ID_X]));
        $second = $index->resolve(new ArtistIdentity('HeadHunterz', [self::ID_X]));

        self::assertSame($first, $second);
        self::assertSame(['Headhunterz', self::ID_X], [$first->name, $first->mbid]);
        self::assertCount(1, $index->artists());
    }

    /** @return iterable<string, array{list<ArtistIdentity>}> */
    public static function nameWithAndWithoutAnId(): iterable
    {
        yield 'without the ID first' => [[new ArtistIdentity('H'), new ArtistIdentity('H', [self::ID_X])]];
        yield 'with the ID first' => [[new ArtistIdentity('H', [self::ID_X]), new ArtistIdentity('H')]];
    }

    /** @param list<ArtistIdentity> $identities */
    #[DataProvider('nameWithAndWithoutAnId')]
    public function testANameWithAndWithoutAnIdConvergesOnOneArtistHoldingTheId(array $identities): void
    {
        $index = new ArtistIdentityIndex([]);

        $resolved = array_map($index->resolve(...), $identities);

        self::assertSame($resolved[0], $resolved[1]);
        self::assertSame(['H', self::ID_X], [$resolved[0]->name, $resolved[0]->mbid]);
        self::assertCount(1, $index->artists());
    }

    public function testAnArtistTheBatchCreatedIsNewerThanEveryStoredOne(): void
    {
        $stored = $this->stored('Nirvana', self::ID_A, '2030-01-01 10:00:00');
        $index = new ArtistIdentityIndex([$stored]);
        $index->resolve(new ArtistIdentity('Nirvana', [self::ID_B]));

        self::assertSame($stored, $index->resolve(new ArtistIdentity('Nirvana')));
    }

    private function stored(string $name, ?string $mbid, string $createdAt): ArtistIdentityCandidate
    {
        return new ArtistIdentityCandidate(new Uuid(), $name, $mbid, new DateTimeImmutable($createdAt));
    }
}
