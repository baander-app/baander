<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure;

use App\Catalog\Application\Command\Person\ManagePersonCommand;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\PersonAdministrationPortInterface;
use App\Catalog\Domain\Model\Artist;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use DateTimeImmutable;

/** The catalog query an admin sync walks to queue the artist syncs, against the migrated schema. */
final class IdentifiedArtistsMissingDetailsTest extends TestCase
{
    private ArtistPortInterface $artists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artists = static::getContainer()->get(ArtistPortInterface::class);
    }

    public function testArtistsWithMissingDetailsOrAnUnlinkedPersonIdentityAreFoundOnce(): void
    {
        $empty = [
            'sortName' => $this->store(self::complete(sortName: null)),
            'type' => $this->store(self::complete(type: null)),
            'country' => $this->store(self::complete(country: null)),
            'disambiguation' => $this->store(self::complete(disambiguation: null)),
            'lifeSpanBegin' => $this->store(self::complete(lifeSpanBegin: null)),
            'lifeSpanEnd' => $this->store(self::complete(lifeSpanEnd: null)),
        ];
        $completePerson = $this->store(self::complete());
        $completeGroup = $this->store(self::complete(type: 'Group'));
        $linked = $this->store(self::complete());
        $people = static::getContainer()->get(PersonAdministrationPortInterface::class);
        $person = $people->execute(new ManagePersonCommand('create', null, null, null, ['name' => 'Linked musician']));
        $people->execute(new ManagePersonCommand('link-artist', $person['publicId'], $linked->getPublicId()->toString(), null, []));
        $withoutId = $this->store(Artist::create('Disturbed'));

        $found = $this->walk(1_000);

        foreach ($empty as $field => $artist) {
            self::assertSame($artist->getMbid(), $found[$artist->getId()->toString()] ?? null, 'An artist without a ' . $field . ' is found.');
        }
        self::assertSame($completePerson->getMbid(), $found[$completePerson->getId()->toString()] ?? null);
        self::assertArrayNotHasKey($completeGroup->getId()->toString(), $found);
        self::assertArrayNotHasKey($linked->getId()->toString(), $found);
        self::assertArrayNotHasKey($withoutId->getId()->toString(), $found);
    }

    public function testThePagesFollowEachOtherInIdOrder(): void
    {
        $stored = array_map(fn (): Artist => $this->store(self::complete(country: null)), range(1, 3));
        $ids = array_map(static fn (Artist $artist): string => $artist->getId()->toString(), $stored);
        sort($ids);

        $walked = array_keys($this->walk(2));

        $walkedStored = array_values(array_intersect($walked, $ids));
        self::assertSame($ids, $walkedStored);
        $sorted = $walked;
        sort($sorted);
        self::assertSame($sorted, $walked);
    }

    /** @return array<string, string> every page, merged */
    private function walk(int $limit): array
    {
        $found = [];
        $after = null;
        do {
            $page = $this->artists->identifiedWithMissingDetailsAfter($after, $limit);
            self::assertLessThanOrEqual($limit, count($page));
            $found += $page;
            $last = array_key_last($page);
            $after = $last === null ? null : Uuid::fromString((string) $last);
        } while (count($page) === $limit);

        return $found;
    }

    private function store(Artist $artist): Artist
    {
        $this->artists->save($artist);

        return $artist;
    }

    private static function complete(
        ?string $sortName = 'Malone, Post',
        ?string $type = 'Person',
        ?string $country = 'US',
        ?string $disambiguation = 'US rapper',
        ?DateTimeImmutable $lifeSpanBegin = new DateTimeImmutable('1995-07-04'),
        ?DateTimeImmutable $lifeSpanEnd = new DateTimeImmutable('2095-07-04'),
    ): Artist {
        return Artist::create(
            'Post Malone ' . bin2hex(random_bytes(4)),
            country: $country,
            type: $type,
            lifeSpanBegin: $lifeSpanBegin,
            lifeSpanEnd: $lifeSpanEnd,
            disambiguation: $disambiguation,
            sortName: $sortName,
            mbid: Uuid::v4()->toString(),
        );
    }
}
