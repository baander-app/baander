<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Model;

use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\AlbumState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AlbumTest extends TestCase
{
    public function testCreateWithRequiredFields(): void
    {
        $album = Album::create(Uuid::v4(), 'Abbey Road', 'Album');

        $this->assertSame('Abbey Road', $album->getTitle());
        $this->assertSame('Album', $album->getType());
        $this->assertNull($album->getMbid());
        $this->assertNull($album->getDiscogsId());
        $this->assertNull($album->getSpotifyId());
        $this->assertNull($album->getYear());
        $this->assertNull($album->getLabel());
        $this->assertNull($album->getCatalogNumber());
        $this->assertNull($album->getBarcode());
        $this->assertNull($album->getCountry());
        $this->assertNull($album->getLanguage());
        $this->assertNull($album->getDisambiguation());
        $this->assertNull($album->getAnnotation());
        $this->assertSame([], $album->getLockedFields());
    }

    public function testCreateWithAllOptionalFields(): void
    {
        $album = Album::create(
            Uuid::v4(),
            'The Dark Side of the Moon',
            'Album',
            mbid: '1f4b1bf0-1f3b-4d2b-8f0d-1a2b3c4d5e6f',
            discogsId: '12345',
            spotifyId: '6nS5rofSTANWiGm2ENb7Os',
            year: 1973,
            label: 'Harvest Records',
            catalogNumber: 'SHVL 804',
            barcode: '5099708040234',
            country: 'United Kingdom',
            language: 'English',
            disambiguation: 'Original UK release',
            annotation: 'Classic progressive rock album',
        );

        $this->assertSame('The Dark Side of the Moon', $album->getTitle());
        $this->assertSame('Album', $album->getType());
        $this->assertSame('1f4b1bf0-1f3b-4d2b-8f0d-1a2b3c4d5e6f', $album->getMbid());
        $this->assertSame('12345', $album->getDiscogsId());
        $this->assertSame('6nS5rofSTANWiGm2ENb7Os', $album->getSpotifyId());
        $this->assertSame(1973, $album->getYear());
        $this->assertSame('Harvest Records', $album->getLabel());
        $this->assertSame('SHVL 804', $album->getCatalogNumber());
        $this->assertSame('5099708040234', $album->getBarcode());
        $this->assertSame('United Kingdom', $album->getCountry());
        $this->assertSame('English', $album->getLanguage());
        $this->assertSame('Original UK release', $album->getDisambiguation());
        $this->assertSame('Classic progressive rock album', $album->getAnnotation());
    }

    public function testCreateThrowsOnEmptyTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Album title cannot be empty.');

        Album::create(Uuid::v4(), '', 'Album');
    }

    public function testCreateThrowsOnWhitespaceOnlyTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Album title cannot be empty.');

        Album::create(Uuid::v4(), '   ', 'Album');
    }

    public function testCreateThrowsOnEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Album type cannot be empty.');

        Album::create(Uuid::v4(), 'Abbey Road', '');
    }

    public function testCreateThrowsOnWhitespaceOnlyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Album type cannot be empty.');

        Album::create(Uuid::v4(), 'Abbey Road', '  ');
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $now = new \DateTimeImmutable();
        $album = Album::reconstitute(new AlbumState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            libraryId: Uuid::v4(),
            title: 'Revolver',
            type: 'Album',
            mbid: 'mbid-123',
            discogsId: 'discogs-456',
            spotifyId: 'spotify-789',
            year: 1966,
            label: 'Parlophone',
            catalogNumber: 'PCS 7009',
            barcode: '0123456789012',
            country: 'United Kingdom',
            language: 'English',
            disambiguation: 'UK mono version',
            annotation: 'Seventh studio album',
            lockedFields: ['title'],
            createdAt: $now,
            updatedAt: $now,
        ));

        $this->assertSame('Revolver', $album->getTitle());
        $this->assertSame('Album', $album->getType());
        $this->assertSame('mbid-123', $album->getMbid());
        $this->assertSame('discogs-456', $album->getDiscogsId());
        $this->assertSame('spotify-789', $album->getSpotifyId());
        $this->assertSame(1966, $album->getYear());
        $this->assertSame('Parlophone', $album->getLabel());
        $this->assertSame('PCS 7009', $album->getCatalogNumber());
        $this->assertSame('0123456789012', $album->getBarcode());
        $this->assertSame('United Kingdom', $album->getCountry());
        $this->assertSame('English', $album->getLanguage());
        $this->assertSame('UK mono version', $album->getDisambiguation());
        $this->assertSame('Seventh studio album', $album->getAnnotation());
        $this->assertSame(['title'], $album->getLockedFields());
    }

    public function testUpdateMetadataTitle(): void
    {
        $album = Album::create(Uuid::v4(), 'Original Title', 'Album');
        $album->updateMetadata(title: 'New Title');

        $this->assertSame('New Title', $album->getTitle());
    }

    public function testUpdateMetadataType(): void
    {
        $album = Album::create(Uuid::v4(), 'Some Album', 'Album');
        $album->updateMetadata(type: 'Single');

        $this->assertSame('Single', $album->getType());
    }

    public function testUpdateMetadataUpdatesAllNullableFields(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');
        $album->updateMetadata(
            year: 2024,
            label: 'New Label',
            catalogNumber: 'CAT-001',
            barcode: '9876543210987',
            country: 'US',
            language: 'Spanish',
            disambiguation: 'Deluxe',
            annotation: 'Updated annotation',
        );

        $this->assertSame(2024, $album->getYear());
        $this->assertSame('New Label', $album->getLabel());
        $this->assertSame('CAT-001', $album->getCatalogNumber());
        $this->assertSame('9876543210987', $album->getBarcode());
        $this->assertSame('US', $album->getCountry());
        $this->assertSame('Spanish', $album->getLanguage());
        $this->assertSame('Deluxe', $album->getDisambiguation());
        $this->assertSame('Updated annotation', $album->getAnnotation());
    }

    public function testUpdateMetadataPreservesFieldsWhenNull(): void
    {
        $album = Album::create(
            Uuid::v4(),
            'Test',
            'Album',
            year: 1999,
            label: 'Old Label',
        );

        $album->updateMetadata();

        $this->assertSame('Test', $album->getTitle());
        $this->assertSame('Album', $album->getType());
        $this->assertSame(1999, $album->getYear());
        $this->assertSame('Old Label', $album->getLabel());
    }

    public function testUpdateMetadataThrowsOnEmptyTitle(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Album title cannot be empty.');

        $album->updateMetadata(title: '');
    }

    public function testUpdateMetadataThrowsOnWhitespaceOnlyTitle(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Album title cannot be empty.');

        $album->updateMetadata(title: '   ');
    }

    public function testUpdateMetadataThrowsOnEmptyType(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Album type cannot be empty.');

        $album->updateMetadata(type: '  ');
    }

    public function testUpdateMetadataSetsUpdatedAt(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');
        $before = $album->getUpdatedAt();

        // Tiny sleep is not needed; just update and check it changed
        $album->updateMetadata(year: 2025);

        $this->assertNotEquals($before, $album->getUpdatedAt());
    }

    public function testUpdateExternalIds(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');
        $album->updateExternalIds(
            mbid: 'new-mbid',
            discogsId: 'new-discogs',
            spotifyId: 'new-spotify',
        );

        $this->assertSame('new-mbid', $album->getMbid());
        $this->assertSame('new-discogs', $album->getDiscogsId());
        $this->assertSame('new-spotify', $album->getSpotifyId());
    }

    public function testUpdateExternalIdsPreservesWhenNull(): void
    {
        $album = Album::create(
            Uuid::v4(),
            'Test',
            'Album',
            mbid: 'existing-mbid',
            discogsId: 'existing-discogs',
        );

        $album->updateExternalIds(spotifyId: 'new-spotify');

        $this->assertSame('existing-mbid', $album->getMbid());
        $this->assertSame('existing-discogs', $album->getDiscogsId());
        $this->assertSame('new-spotify', $album->getSpotifyId());
    }

    public function testUpdateExternalIdsSetsUpdatedAt(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');
        $before = $album->getUpdatedAt();

        $album->updateExternalIds(mbid: 'mbid');

        $this->assertNotEquals($before, $album->getUpdatedAt());
    }

    public function testLockField(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');
        $album->lockField('title');

        $this->assertTrue($album->isFieldLocked('title'));
        $this->assertFalse($album->isFieldLocked('type'));
    }

    public function testLockFieldIsIdempotent(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');
        $album->lockField('title');
        $before = $album->getUpdatedAt();

        $album->lockField('title');

        // Locking an already-locked field does not update timestamp
        $this->assertSame($before, $album->getUpdatedAt());
    }

    public function testUnlockField(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');
        $album->lockField('title');
        $album->unlockField('title');

        $this->assertFalse($album->isFieldLocked('title'));
    }

    public function testUnlockFieldReindexesArray(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');
        $album->lockField('title');
        $album->lockField('type');
        $album->unlockField('title');

        $this->assertSame(['type'], $album->getLockedFields());
        $this->assertSame([0], array_keys($album->getLockedFields()));
    }

    public function testUnlockFieldSetsUpdatedAt(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');
        $album->lockField('title');
        $before = $album->getUpdatedAt();
        $album->unlockField('title');

        $this->assertNotEquals($before, $album->getUpdatedAt());
    }

    public function testUpdateMetadataThrowsOnLockedTitle(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');
        $album->lockField('title');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "title" is locked and cannot be updated.');

        $album->updateMetadata(title: 'New Title');
    }

    public function testUpdateMetadataThrowsOnLockedType(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');
        $album->lockField('type');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "type" is locked and cannot be updated.');

        $album->updateMetadata(type: 'EP');
    }

    public function testUpdateMetadataAllowsUnlockedFieldWhenOtherIsLocked(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');
        $album->lockField('title');
        $album->updateMetadata(type: 'Single');

        $this->assertSame('Test', $album->getTitle());
        $this->assertSame('Single', $album->getType());
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');

        $this->assertInstanceOf(Uuid::class, $album->getId());
        $this->assertInstanceOf(PublicId::class, $album->getPublicId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $album->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $album->getUpdatedAt());
    }

    public function testCreatedAtAndUpdatedAtAreCloseOnCreation(): void
    {
        $album = Album::create(Uuid::v4(), 'Test', 'Album');

        $diff = $album->getUpdatedAt()->getTimestamp() - $album->getCreatedAt()->getTimestamp();
        $this->assertSame(0, $diff, 'createdAt and updatedAt should have the same second on creation.');
    }

    public function testCreateHasNullCoverImageIdByDefault(): void
    {
        $album = Album::create(Uuid::v4(), 'Abbey Road', 'Album');

        $this->assertNull($album->getCoverImageId());
    }

    public function testSetCoverImageSetsIdAndUpdatesTimestamp(): void
    {
        $album = Album::create(Uuid::v4(), 'Abbey Road', 'Album');
        $before = $album->getUpdatedAt();

        $imageId = Uuid::v4();
        $album->setCoverImage($imageId);

        $this->assertSame($imageId->toString(), $album->getCoverImageId()->toString());
        $this->assertNotEquals($before, $album->getUpdatedAt());
    }

    public function testSetCoverImageNullClearsId(): void
    {
        $album = Album::create(Uuid::v4(), 'Abbey Road', 'Album');
        $album->setCoverImage(Uuid::v4());

        $this->assertNotNull($album->getCoverImageId());

        $album->setCoverImage(null);

        $this->assertNull($album->getCoverImageId());
    }

    public function testReconstitutePreservesCoverImageId(): void
    {
        $now = new \DateTimeImmutable();
        $imageId = Uuid::v4();
        $album = Album::reconstitute(new AlbumState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            libraryId: Uuid::v4(),
            title: 'Revolver',
            type: 'Album',
            mbid: null,
            discogsId: null,
            spotifyId: null,
            year: 1966,
            label: null,
            catalogNumber: null,
            barcode: null,
            country: null,
            language: null,
            disambiguation: null,
            annotation: null,
            coverImageId: $imageId,
            lockedFields: [],
            createdAt: $now,
            updatedAt: $now,
        ));

        $this->assertSame($imageId->toString(), $album->getCoverImageId()->toString());
    }

    public function testReconstituteWithNullCoverImageId(): void
    {
        $now = new \DateTimeImmutable();
        $album = Album::reconstitute(new AlbumState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            libraryId: Uuid::v4(),
            title: 'Revolver',
            type: 'Album',
            mbid: null,
            discogsId: null,
            spotifyId: null,
            year: 1966,
            label: null,
            catalogNumber: null,
            barcode: null,
            country: null,
            language: null,
            disambiguation: null,
            annotation: null,
            coverImageId: null,
            lockedFields: [],
            createdAt: $now,
            updatedAt: $now,
        ));

        $this->assertNull($album->getCoverImageId());
    }
}
