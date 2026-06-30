<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Artist;
use App\Catalog\Interface\Resource\ArtistResource;
use PHPUnit\Framework\TestCase;

final class ArtistResourceTest extends TestCase
{
    private Artist $artist;

    protected function setUp(): void
    {
        $this->artist = Artist::create(
            'The Beatles',
            country: 'United Kingdom',
            gender: 'Male',
            type: 'Group',
            disambiguation: 'British rock band',
            sortName: 'Beatles, The',
            mbid: 'b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d',
            discogsId: '18839',
            spotifyId: '3WrFJ7ztbogyGnTHbHJFl2',
        );
    }

    public function testFromReturnsExpectedKeys(): void
    {
        $result = ArtistResource::from($this->artist);

        $this->assertArrayHasKey('uuid', $result);
        $this->assertArrayHasKey('publicId', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('country', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('disambiguation', $result);
        $this->assertArrayHasKey('sortName', $result);
        $this->assertArrayHasKey('createdAt', $result);
    }

    public function testFromTransformsValues(): void
    {
        $result = ArtistResource::from($this->artist);

        $this->assertSame('The Beatles', $result['name']);
        $this->assertSame('United Kingdom', $result['country']);
        $this->assertSame('Group', $result['type']);
        $this->assertSame('British rock band', $result['disambiguation']);
        $this->assertSame('Beatles, The', $result['sortName']);
    }

    public function testFromContainsUuidAsNonEmptyString(): void
    {
        $result = ArtistResource::from($this->artist);

        $this->assertIsString($result['uuid']);
        $this->assertNotEmpty($result['uuid']);
    }

    public function testFromContainsPublicIdAsNonEmptyString(): void
    {
        $result = ArtistResource::from($this->artist);

        $this->assertIsString($result['publicId']);
        $this->assertNotEmpty($result['publicId']);
    }

    public function testFromContainsCreatedAtInAtomFormat(): void
    {
        $result = ArtistResource::from($this->artist);

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $result['createdAt']);
        $this->assertNotFalse($parsed, 'createdAt should be a valid ATOM-formatted string');
        $this->assertSame(
            $this->artist->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $result['createdAt'],
        );
    }

    public function testFromDoesNotExposeSensitiveFields(): void
    {
        $result = ArtistResource::from($this->artist);

        $this->assertArrayNotHasKey('gender', $result);
        $this->assertArrayNotHasKey('biography', $result);
        $this->assertArrayNotHasKey('mbid', $result);
        $this->assertArrayNotHasKey('discogsId', $result);
        $this->assertArrayNotHasKey('spotifyId', $result);
        $this->assertArrayNotHasKey('lifeSpanBegin', $result);
        $this->assertArrayNotHasKey('lifeSpanEnd', $result);
        $this->assertArrayNotHasKey('updatedAt', $result);
        $this->assertArrayNotHasKey('lockedFields', $result);
    }

    public function testFromHandlesNullOptionalFields(): void
    {
        $artist = Artist::create('Minimal Artist');

        $result = ArtistResource::from($artist);

        $this->assertNull($result['country']);
        $this->assertNull($result['type']);
        $this->assertNull($result['disambiguation']);
        $this->assertNull($result['sortName']);
    }

    public function testCollectionTransformsMultipleArtists(): void
    {
        $artist1 = Artist::create('Artist One', country: 'US');
        $artist2 = Artist::create('Artist Two', country: 'UK');

        $result = ArtistResource::collection([$artist1, $artist2]);

        $this->assertCount(2, $result);
        $this->assertSame('Artist One', $result[0]['name']);
        $this->assertSame('Artist Two', $result[1]['name']);
        $this->assertSame('US', $result[0]['country']);
        $this->assertSame('UK', $result[1]['country']);
    }

    public function testCollectionReturnsEmptyArrayForEmptyInput(): void
    {
        $result = ArtistResource::collection([]);

        $this->assertSame([], $result);
    }
}
