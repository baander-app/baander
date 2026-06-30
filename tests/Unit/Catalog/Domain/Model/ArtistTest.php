<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Model;

use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\ArtistState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ArtistTest extends TestCase
{
    public function testCreateWithRequiredFields(): void
    {
        $artist = Artist::create('The Beatles');

        $this->assertSame('The Beatles', $artist->getName());
        $this->assertNull($artist->getCountry());
        $this->assertNull($artist->getGender());
        $this->assertNull($artist->getType());
        $this->assertNull($artist->getLifeSpanBegin());
        $this->assertNull($artist->getLifeSpanEnd());
        $this->assertNull($artist->getDisambiguation());
        $this->assertNull($artist->getSortName());
        $this->assertNull($artist->getBiography());
        $this->assertNull($artist->getMbid());
        $this->assertNull($artist->getDiscogsId());
        $this->assertNull($artist->getSpotifyId());
        $this->assertSame([], $artist->getLockedFields());
    }

    public function testCreateWithAllOptionalFields(): void
    {
        $lifeBegin = new DateTimeImmutable('1960-01-01');
        $lifeEnd = new DateTimeImmutable('1970-04-10');

        $artist = Artist::create(
            'The Beatles',
            country: 'United Kingdom',
            gender: 'Male',
            type: 'Group',
            lifeSpanBegin: $lifeBegin,
            lifeSpanEnd: $lifeEnd,
            disambiguation: 'British rock band',
            sortName: 'Beatles, The',
            biography: 'The Beatles were an English rock band.',
            mbid: 'b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d',
            discogsId: '18839',
            spotifyId: '3WrFJ7ztbogyGnTHbHJFl2',
        );

        $this->assertSame('The Beatles', $artist->getName());
        $this->assertSame('United Kingdom', $artist->getCountry());
        $this->assertSame('Male', $artist->getGender());
        $this->assertSame('Group', $artist->getType());
        $this->assertEquals($lifeBegin, $artist->getLifeSpanBegin());
        $this->assertEquals($lifeEnd, $artist->getLifeSpanEnd());
        $this->assertSame('British rock band', $artist->getDisambiguation());
        $this->assertSame('Beatles, The', $artist->getSortName());
        $this->assertSame('The Beatles were an English rock band.', $artist->getBiography());
        $this->assertSame('b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d', $artist->getMbid());
        $this->assertSame('18839', $artist->getDiscogsId());
        $this->assertSame('3WrFJ7ztbogyGnTHbHJFl2', $artist->getSpotifyId());
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Artist name cannot be empty.');

        Artist::create('');
    }

    public function testCreateThrowsOnWhitespaceOnlyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Artist name cannot be empty.');

        Artist::create('   ');
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $now = new DateTimeImmutable();
        $lifeBegin = new DateTimeImmutable('1950-01-01');
        $artist = Artist::reconstitute(new ArtistState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            name: 'Miles Davis',
            country: 'United States',
            gender: 'Male',
            type: 'Person',
            lifeSpanBegin: $lifeBegin,
            lifeSpanEnd: null,
            disambiguation: 'American jazz musician',
            sortName: 'Davis, Miles',
            biography: 'Miles Dewey Davis III was an American jazz trumpeter.',
            mbid: '56d35c5a-5b70-4626-b3cd-d93f45d09e6b',
            discogsId: '11270',
            spotifyId: '0kbYtQrTdV9HnSw69oqRom',
            lockedFields: ['name'],
            createdAt: $now,
            updatedAt: $now,
        ));

        $this->assertSame('Miles Davis', $artist->getName());
        $this->assertSame('United States', $artist->getCountry());
        $this->assertSame('Male', $artist->getGender());
        $this->assertSame('Person', $artist->getType());
        $this->assertEquals($lifeBegin, $artist->getLifeSpanBegin());
        $this->assertNull($artist->getLifeSpanEnd());
        $this->assertSame('American jazz musician', $artist->getDisambiguation());
        $this->assertSame('Davis, Miles', $artist->getSortName());
        $this->assertSame('Miles Dewey Davis III was an American jazz trumpeter.', $artist->getBiography());
        $this->assertSame('56d35c5a-5b70-4626-b3cd-d93f45d09e6b', $artist->getMbid());
        $this->assertSame('11270', $artist->getDiscogsId());
        $this->assertSame('0kbYtQrTdV9HnSw69oqRom', $artist->getSpotifyId());
        $this->assertSame(['name'], $artist->getLockedFields());
    }

    public function testUpdateMetadataName(): void
    {
        $artist = Artist::create('Original Name');
        $artist->updateMetadata(name: 'New Name');

        $this->assertSame('New Name', $artist->getName());
    }

    public function testUpdateMetadataUpdatesAllNullableFields(): void
    {
        $artist = Artist::create('Test Artist');
        $lifeBegin = new DateTimeImmutable('1980-01-01');
        $lifeEnd = new DateTimeImmutable('2020-12-31');

        $artist->updateMetadata(
            country: 'Germany',
            gender: 'Female',
            type: 'Person',
            lifeSpanBegin: $lifeBegin,
            lifeSpanEnd: $lifeEnd,
            disambiguation: 'German artist',
            sortName: 'Artist, Test',
            biography: 'A test artist biography.',
        );

        $this->assertSame('Germany', $artist->getCountry());
        $this->assertSame('Female', $artist->getGender());
        $this->assertSame('Person', $artist->getType());
        $this->assertEquals($lifeBegin, $artist->getLifeSpanBegin());
        $this->assertEquals($lifeEnd, $artist->getLifeSpanEnd());
        $this->assertSame('German artist', $artist->getDisambiguation());
        $this->assertSame('Artist, Test', $artist->getSortName());
        $this->assertSame('A test artist biography.', $artist->getBiography());
    }

    public function testUpdateMetadataPreservesFieldsWhenNull(): void
    {
        $artist = Artist::create(
            'Test',
            country: 'US',
            gender: 'Male',
        );

        $artist->updateMetadata();

        $this->assertSame('Test', $artist->getName());
        $this->assertSame('US', $artist->getCountry());
        $this->assertSame('Male', $artist->getGender());
    }

    public function testUpdateMetadataThrowsOnEmptyName(): void
    {
        $artist = Artist::create('Test');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Artist name cannot be empty.');

        $artist->updateMetadata(name: '');
    }

    public function testUpdateMetadataThrowsOnWhitespaceOnlyName(): void
    {
        $artist = Artist::create('Test');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Artist name cannot be empty.');

        $artist->updateMetadata(name: '  ');
    }

    public function testUpdateMetadataSetsUpdatedAt(): void
    {
        $artist = Artist::create('Test');
        $before = $artist->getUpdatedAt();

        $artist->updateMetadata(country: 'FR');

        $this->assertNotEquals($before, $artist->getUpdatedAt());
    }

    public function testUpdateExternalIds(): void
    {
        $artist = Artist::create('Test');
        $artist->updateExternalIds(
            mbid: 'new-mbid',
            discogsId: 'new-discogs',
            spotifyId: 'new-spotify',
        );

        $this->assertSame('new-mbid', $artist->getMbid());
        $this->assertSame('new-discogs', $artist->getDiscogsId());
        $this->assertSame('new-spotify', $artist->getSpotifyId());
    }

    public function testUpdateExternalIdsPreservesWhenNull(): void
    {
        $artist = Artist::create(
            'Test',
            mbid: 'existing-mbid',
        );

        $artist->updateExternalIds(spotifyId: 'new-spotify');

        $this->assertSame('existing-mbid', $artist->getMbid());
        $this->assertSame('new-spotify', $artist->getSpotifyId());
    }

    public function testUpdateExternalIdsSetsUpdatedAt(): void
    {
        $artist = Artist::create('Test');
        $before = $artist->getUpdatedAt();

        $artist->updateExternalIds(mbid: 'mbid');

        $this->assertNotEquals($before, $artist->getUpdatedAt());
    }

    public function testLockField(): void
    {
        $artist = Artist::create('Test');
        $artist->lockField('name');

        $this->assertTrue($artist->isFieldLocked('name'));
        $this->assertFalse($artist->isFieldLocked('country'));
    }

    public function testLockFieldIsIdempotent(): void
    {
        $artist = Artist::create('Test');
        $artist->lockField('name');
        $before = $artist->getUpdatedAt();

        $artist->lockField('name');

        $this->assertSame($before, $artist->getUpdatedAt());
    }

    public function testUnlockField(): void
    {
        $artist = Artist::create('Test');
        $artist->lockField('name');
        $artist->unlockField('name');

        $this->assertFalse($artist->isFieldLocked('name'));
    }

    public function testUnlockFieldSetsUpdatedAt(): void
    {
        $artist = Artist::create('Test');
        $artist->lockField('name');
        $before = $artist->getUpdatedAt();
        $artist->unlockField('name');

        $this->assertNotEquals($before, $artist->getUpdatedAt());
    }

    public function testUpdateMetadataThrowsOnLockedName(): void
    {
        $artist = Artist::create('Test');
        $artist->lockField('name');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "name" is locked and cannot be updated.');

        $artist->updateMetadata(name: 'New Name');
    }

    public function testUpdateMetadataAllowsUnlockedFieldWhenNameIsLocked(): void
    {
        $artist = Artist::create('Test');
        $artist->lockField('name');
        $artist->updateMetadata(country: 'Japan');

        $this->assertSame('Test', $artist->getName());
        $this->assertSame('Japan', $artist->getCountry());
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $artist = Artist::create('Test');

        $this->assertInstanceOf(Uuid::class, $artist->getId());
        $this->assertInstanceOf(PublicId::class, $artist->getPublicId());
        $this->assertInstanceOf(DateTimeImmutable::class, $artist->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $artist->getUpdatedAt());
    }

    public function testCreatedAtAndUpdatedAtAreCloseOnCreation(): void
    {
        $artist = Artist::create('Test');

        $diff = $artist->getUpdatedAt()->getTimestamp() - $artist->getCreatedAt()->getTimestamp();
        $this->assertSame(0, $diff, 'createdAt and updatedAt should have the same second on creation.');
    }
}
