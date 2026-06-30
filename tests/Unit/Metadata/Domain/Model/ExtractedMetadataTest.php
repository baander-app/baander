<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Domain\Model;

use App\Metadata\Domain\Model\CoverArt;
use App\Metadata\Domain\Model\ExtractedMetadata;
use PHPUnit\Framework\TestCase;

final class ExtractedMetadataTest extends TestCase
{
    public function testConstructionWithAllNulls(): void
    {
        $metadata = new ExtractedMetadata();

        $this->assertNull($metadata->getTitle());
        $this->assertNull($metadata->getAlbum());
        $this->assertNull($metadata->getArtist());
        $this->assertNull($metadata->getAlbumArtist());
        $this->assertNull($metadata->getTrackNumber());
        $this->assertNull($metadata->getDiscNumber());
        $this->assertNull($metadata->getYear());
        $this->assertSame([], $metadata->getGenre());
        $this->assertNull($metadata->getComment());
        $this->assertNull($metadata->getComposer());
        $this->assertNull($metadata->getBpm());
        $this->assertNull($metadata->getDuration());
        $this->assertNull($metadata->getBitrate());
        $this->assertNull($metadata->getSampleRate());
        $this->assertNull($metadata->getChannels());
        $this->assertNull($metadata->getMbid());
        $this->assertNull($metadata->getMbAlbumId());
        $this->assertNull($metadata->getMbArtistId());
        $this->assertSame([], $metadata->getCoverArt());
    }

    public function testConstructionWithValues(): void
    {
        $metadata = new ExtractedMetadata(
            title: 'Song Title',
            album: 'Great Album',
            artist: 'The Artist',
            albumArtist: 'Various',
            trackNumber: 5,
            discNumber: 1,
            year: 2024,
            genre: ['Rock', 'Alternative'],
            comment: 'A comment',
            composer: 'Composer Name',
            bpm: 120,
            duration: 245.5,
            bitrate: 320,
            sampleRate: 44100,
            channels: 2,
            mbid: 'mb-id-123',
            mbAlbumId: 'mb-album-456',
            mbArtistId: 'mb-artist-789',
            coverArt: ['cover.jpg'],
        );

        $this->assertSame('Song Title', $metadata->getTitle());
        $this->assertSame('Great Album', $metadata->getAlbum());
        $this->assertSame('The Artist', $metadata->getArtist());
        $this->assertSame('Various', $metadata->getAlbumArtist());
        $this->assertSame(5, $metadata->getTrackNumber());
        $this->assertSame(1, $metadata->getDiscNumber());
        $this->assertSame(2024, $metadata->getYear());
        $this->assertSame(['Rock', 'Alternative'], $metadata->getGenre());
        $this->assertSame('A comment', $metadata->getComment());
        $this->assertSame('Composer Name', $metadata->getComposer());
        $this->assertSame(120, $metadata->getBpm());
        $this->assertSame(245.5, $metadata->getDuration());
        $this->assertSame(320, $metadata->getBitrate());
        $this->assertSame(44100, $metadata->getSampleRate());
        $this->assertSame(2, $metadata->getChannels());
        $this->assertSame('mb-id-123', $metadata->getMbid());
        $this->assertSame('mb-album-456', $metadata->getMbAlbumId());
        $this->assertSame('mb-artist-789', $metadata->getMbArtistId());
        $this->assertSame(['cover.jpg'], $metadata->getCoverArt());
    }

    public function testBuilderPatternSetTitle(): void
    {
        $metadata = new ExtractedMetadata();
        $result = $metadata->setTitle('New Title');

        $this->assertSame($metadata, $result);
        $this->assertSame('New Title', $metadata->getTitle());
    }

    public function testBuilderPatternSetAlbum(): void
    {
        $metadata = new ExtractedMetadata();
        $result = $metadata->setAlbum('New Album');

        $this->assertSame($metadata, $result);
        $this->assertSame('New Album', $metadata->getAlbum());
    }

    public function testBuilderPatternSetArtist(): void
    {
        $metadata = new ExtractedMetadata();
        $result = $metadata->setArtist('New Artist');

        $this->assertSame($metadata, $result);
        $this->assertSame('New Artist', $metadata->getArtist());
    }

    public function testBuilderPatternSetGenre(): void
    {
        $metadata = new ExtractedMetadata();
        $result = $metadata->setGenre(['Pop', 'Electronic']);

        $this->assertSame($metadata, $result);
        $this->assertSame(['Pop', 'Electronic'], $metadata->getGenre());
    }

    public function testBuilderPatternSetNumericFields(): void
    {
        $metadata = new ExtractedMetadata();

        $metadata->setTrackNumber(3)
            ->setDiscNumber(2)
            ->setYear(2023)
            ->setBpm(140)
            ->setDuration(300.0)
            ->setBitrate(256)
            ->setSampleRate(48000)
            ->setChannels(1);

        $this->assertSame(3, $metadata->getTrackNumber());
        $this->assertSame(2, $metadata->getDiscNumber());
        $this->assertSame(2023, $metadata->getYear());
        $this->assertSame(140, $metadata->getBpm());
        $this->assertSame(300.0, $metadata->getDuration());
        $this->assertSame(256, $metadata->getBitrate());
        $this->assertSame(48000, $metadata->getSampleRate());
        $this->assertSame(1, $metadata->getChannels());
    }

    public function testBuilderPatternSetMusicBrainzIds(): void
    {
        $metadata = new ExtractedMetadata();

        $metadata->setMbid('rec-123')
            ->setMbAlbumId('alb-456')
            ->setMbArtistId('art-789');

        $this->assertSame('rec-123', $metadata->getMbid());
        $this->assertSame('alb-456', $metadata->getMbAlbumId());
        $this->assertSame('art-789', $metadata->getMbArtistId());
    }

    public function testBuilderPatternChainingMultipleSetters(): void
    {
        $metadata = new ExtractedMetadata();
        $result = $metadata
            ->setTitle('Chained Title')
            ->setArtist('Chained Artist')
            ->setAlbum('Chained Album')
            ->setYear(2025);

        $this->assertSame($metadata, $result);
        $this->assertSame('Chained Title', $metadata->getTitle());
        $this->assertSame('Chained Artist', $metadata->getArtist());
        $this->assertSame('Chained Album', $metadata->getAlbum());
        $this->assertSame(2025, $metadata->getYear());
    }

    public function testBuilderPatternSetNullableFieldsToNull(): void
    {
        $metadata = new ExtractedMetadata(
            title: 'Title',
            artist: 'Artist',
        );

        $metadata->setTitle(null)->setArtist(null);

        $this->assertNull($metadata->getTitle());
        $this->assertNull($metadata->getArtist());
    }

    public function testFromArrayWithBasicFields(): void
    {
        $metadata = ExtractedMetadata::fromArray([
            'title' => 'FromArray Title',
            'album' => 'FromArray Album',
            'artist' => 'FromArray Artist',
            'year' => 2024,
        ]);

        $this->assertSame('FromArray Title', $metadata->getTitle());
        $this->assertSame('FromArray Album', $metadata->getAlbum());
        $this->assertSame('FromArray Artist', $metadata->getArtist());
        $this->assertSame(2024, $metadata->getYear());
    }

    public function testFromArrayWithGenreAsArray(): void
    {
        $metadata = ExtractedMetadata::fromArray([
            'genre' => ['Rock', 'Pop'],
        ]);

        $this->assertSame(['Rock', 'Pop'], $metadata->getGenre());
    }

    public function testFromArrayWithGenreAsCommaSeparatedString(): void
    {
        $metadata = ExtractedMetadata::fromArray([
            'genre' => 'Rock, Pop, Jazz',
        ]);

        $this->assertSame(['Rock', 'Pop', 'Jazz'], $metadata->getGenre());
    }

    public function testFromArrayWithEmptyGenreString(): void
    {
        $metadata = ExtractedMetadata::fromArray([
            'genre' => '',
        ]);

        $this->assertSame([], $metadata->getGenre());
    }

    public function testFromArrayWithAllFields(): void
    {
        $metadata = ExtractedMetadata::fromArray([
            'title' => 'Full Song',
            'album' => 'Full Album',
            'artist' => 'Full Artist',
            'albumArtist' => 'Full Album Artist',
            'trackNumber' => 7,
            'discNumber' => 2,
            'year' => 2022,
            'genre' => ['Electronic', 'Ambient'],
            'comment' => 'Great track',
            'composer' => 'DJ Mix',
            'bpm' => 128,
            'duration' => 360.5,
            'bitrate' => 320,
            'sampleRate' => 44100,
            'channels' => 2,
            'mbid' => 'uuid-1',
            'mbAlbumId' => 'uuid-2',
            'mbArtistId' => 'uuid-3',
            'coverArt' => ['front.jpg', 'back.jpg'],
        ]);

        $this->assertSame('Full Song', $metadata->getTitle());
        $this->assertSame('Full Album', $metadata->getAlbum());
        $this->assertSame('Full Artist', $metadata->getArtist());
        $this->assertSame('Full Album Artist', $metadata->getAlbumArtist());
        $this->assertSame(7, $metadata->getTrackNumber());
        $this->assertSame(2, $metadata->getDiscNumber());
        $this->assertSame(2022, $metadata->getYear());
        $this->assertSame(['Electronic', 'Ambient'], $metadata->getGenre());
        $this->assertSame('Great track', $metadata->getComment());
        $this->assertSame('DJ Mix', $metadata->getComposer());
        $this->assertSame(128, $metadata->getBpm());
        $this->assertSame(360.5, $metadata->getDuration());
        $this->assertSame(320, $metadata->getBitrate());
        $this->assertSame(44100, $metadata->getSampleRate());
        $this->assertSame(2, $metadata->getChannels());
        $this->assertSame('uuid-1', $metadata->getMbid());
        $this->assertSame('uuid-2', $metadata->getMbAlbumId());
        $this->assertSame('uuid-3', $metadata->getMbArtistId());
        $this->assertSame(['front.jpg', 'back.jpg'], $metadata->getCoverArt());
    }

    public function testFromArrayWithEmptyDataReturnsDefaults(): void
    {
        $metadata = ExtractedMetadata::fromArray([]);

        $this->assertNull($metadata->getTitle());
        $this->assertNull($metadata->getAlbum());
        $this->assertNull($metadata->getArtist());
        $this->assertSame([], $metadata->getGenre());
        $this->assertSame([], $metadata->getCoverArt());
    }

    public function testFromArrayConvertsEmptyStringToNull(): void
    {
        $metadata = ExtractedMetadata::fromArray([
            'title' => '  ',
            'artist' => '',
        ]);

        $this->assertNull($metadata->getTitle());
        $this->assertNull($metadata->getArtist());
    }

    public function testEmptyGenreArrayNormalizedToEmptyArray(): void
    {
        $metadata = new ExtractedMetadata(genre: []);

        $this->assertSame([], $metadata->getGenre());
    }

    public function testEmptyCoverArtArrayNormalizedToEmptyArray(): void
    {
        $metadata = new ExtractedMetadata(coverArt: []);

        $this->assertSame([], $metadata->getCoverArt());
    }

    public function testSetCoverArtReturnsSelf(): void
    {
        $metadata = new ExtractedMetadata();
        $result = $metadata->setCoverArt(['image.png']);

        $this->assertSame($metadata, $result);
        $this->assertSame(['image.png'], $metadata->getCoverArt());
    }

    // ---- Pictures (CoverArt) ----

    public function testDefaultPicturesIsEmpty(): void
    {
        $metadata = new ExtractedMetadata();

        $this->assertSame([], $metadata->getPictures());
    }

    public function testSetPicturesAndGetPictures(): void
    {
        $cover = new CoverArt(type: 3, mimeType: 'image/jpeg', description: 'front', imageData: 'abc');

        $metadata = new ExtractedMetadata();
        $result = $metadata->setPictures([$cover]);

        $this->assertSame($metadata, $result);
        $this->assertCount(1, $metadata->getPictures());
        $this->assertSame($cover, $metadata->getPictures()[0]);
    }

    public function testGetFrontCoverReturnsCoverFrontWhenPresent(): void
    {
        $back = new CoverArt(type: 4, mimeType: 'image/jpeg', description: 'back', imageData: 'xyz');
        $front = new CoverArt(type: 3, mimeType: 'image/jpeg', description: 'front', imageData: 'abc');

        $metadata = new ExtractedMetadata();
        $metadata->setPictures([$back, $front]);

        $result = $metadata->getFrontCover();

        $this->assertSame($front, $result);
    }

    public function testGetFrontCoverFallsBackToFirstPicture(): void
    {
        $back = new CoverArt(type: 4, mimeType: 'image/jpeg', description: 'back', imageData: 'xyz');

        $metadata = new ExtractedMetadata();
        $metadata->setPictures([$back]);

        $result = $metadata->getFrontCover();

        $this->assertSame($back, $result);
    }

    public function testGetFrontCoverReturnsNullWhenNoPictures(): void
    {
        $metadata = new ExtractedMetadata();

        $this->assertNull($metadata->getFrontCover());
    }
}
