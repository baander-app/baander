<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Model;

use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\SongState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SongTest extends TestCase
{
    private Uuid $albumId;

    protected function setUp(): void
    {
        $this->albumId = Uuid::v4();
    }

    public function testCreateWithRequiredFields(): void
    {
        $song = Song::create($this->albumId, 'Yesterday', '/music/beatles/yesterday.mp3', 4096, 'audio/mpeg');

        $this->assertSame('Yesterday', $song->getTitle());
        $this->assertSame('/music/beatles/yesterday.mp3', $song->getPath());
        $this->assertSame(4096, $song->getSize());
        $this->assertSame('audio/mpeg', $song->getMimeType());
        $this->assertNull($song->getLength());
        $this->assertNull($song->getLyrics());
        $this->assertNull($song->getTrack());
        $this->assertNull($song->getDisc());
        $this->assertNull($song->getYear());
        $this->assertNull($song->getComment());
        $this->assertNull($song->getHash());
        $this->assertNull($song->getBitrate());
        $this->assertNull($song->getSampleRate());
        $this->assertNull($song->getChannels());
        $this->assertNull($song->getCodec());
        $this->assertFalse($song->isExplicit());
        $this->assertNull($song->getMbid());
        $this->assertNull($song->getDiscogsId());
        $this->assertNull($song->getSpotifyId());
        $this->assertNull($song->getEnergy());
        $this->assertNull($song->getDanceability());
        $this->assertNull($song->getValence());
        $this->assertNull($song->getAcousticness());
        $this->assertNull($song->getInstrumentalness());
        $this->assertNull($song->getLiveness());
        $this->assertNull($song->getSpechiness());
        $this->assertNull($song->getLoudness());
        $this->assertSame([], $song->getLockedFields());
    }

    public function testCreateWithAllOptionalFields(): void
    {
        $song = Song::create(
            $this->albumId,
            'Bohemian Rhapsody',
            '/music/queen/bohemian.mp3',
            8192,
            'audio/flac',
            length: 354.5,
            lyrics: 'Is this the real life...',
            track: 1,
            disc: 1,
            year: 1975,
            comment: 'Master track',
            hash: 'sha256:abc123',
            bitrate: 320,
            sampleRate: 44100,
            channels: 2,
            codec: 'FLAC',
            explicit: true,
        );

        $this->assertSame('Bohemian Rhapsody', $song->getTitle());
        $this->assertSame(8192, $song->getSize());
        $this->assertSame('audio/flac', $song->getMimeType());
        $this->assertSame(354.5, $song->getLength());
        $this->assertSame('Is this the real life...', $song->getLyrics());
        $this->assertSame(1, $song->getTrack());
        $this->assertSame(1, $song->getDisc());
        $this->assertSame(1975, $song->getYear());
        $this->assertSame('Master track', $song->getComment());
        $this->assertSame('sha256:abc123', $song->getHash());
        $this->assertSame(320, $song->getBitrate());
        $this->assertSame(44100, $song->getSampleRate());
        $this->assertSame(2, $song->getChannels());
        $this->assertSame('FLAC', $song->getCodec());
        $this->assertTrue($song->isExplicit());
    }

    public function testCreateThrowsOnEmptyTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Song title cannot be empty.');

        Song::create($this->albumId, '', '/path/song.mp3', 4096, 'audio/mpeg');
    }

    public function testCreateThrowsOnWhitespaceOnlyTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Song title cannot be empty.');

        Song::create($this->albumId, '  ', '/path/song.mp3', 4096, 'audio/mpeg');
    }

    public function testCreateThrowsOnEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Song path cannot be empty.');

        Song::create($this->albumId, 'Title', '', 4096, 'audio/mpeg');
    }

    public function testCreateThrowsOnWhitespaceOnlyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Song path cannot be empty.');

        Song::create($this->albumId, 'Title', '   ', 4096, 'audio/mpeg');
    }

    public function testCreateThrowsOnNegativeSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Song size must be non-negative.');

        Song::create($this->albumId, 'Title', '/path/song.mp3', -1, 'audio/mpeg');
    }

    public function testCreateAllowsZeroSize(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path/song.mp3', 0, 'audio/mpeg');

        $this->assertSame(0, $song->getSize());
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $now = new \DateTimeImmutable();
        $song = Song::reconstitute(new SongState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            album: $this->albumId,
            title: 'Hotel California',
            path: '/music/eagles/hotel.mp3',
            size: 65536,
            mimeType: 'audio/mpeg',
            length: 391.0,
            lyrics: 'Welcome to the Hotel California',
            track: 1,
            disc: 1,
            year: 1977,
            comment: 'Classic rock',
            hash: 'sha256:def456',
            bitrate: 256,
            sampleRate: 48000,
            channels: 2,
            codec: 'MP3',
            explicit: false,
            energy: 0.85,
            danceability: 0.6,
            valence: 0.4,
            acousticness: 0.1,
            instrumentalness: 0.0,
            liveness: 0.2,
            spechiness: 0.05,
            loudness: -5.3,
            mbid: 'song-mbid-123',
            discogsId: 'song-discogs-456',
            spotifyId: 'song-spotify-789',
            lockedFields: ['title'],
            createdAt: $now,
            updatedAt: $now,
        ));

        $this->assertSame('Hotel California', $song->getTitle());
        $this->assertSame($this->albumId, $song->getAlbumId());
        $this->assertSame(391.0, $song->getLength());
        $this->assertSame(0.85, $song->getEnergy());
        $this->assertSame('song-mbid-123', $song->getMbid());
        $this->assertSame(['title'], $song->getLockedFields());
    }

    public function testUpdateMetadataTitle(): void
    {
        $song = Song::create($this->albumId, 'Old Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->updateMetadata(title: 'New Title');

        $this->assertSame('New Title', $song->getTitle());
    }

    public function testUpdateMetadataUpdatesTrackDiscAndYear(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->updateMetadata(track: 5, disc: 2, year: 2024);

        $this->assertSame(5, $song->getTrack());
        $this->assertSame(2, $song->getDisc());
        $this->assertSame(2024, $song->getYear());
    }

    public function testUpdateMetadataUpdatesCommentAndLyrics(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->updateMetadata(comment: 'Great track', lyrics: 'La la la');

        $this->assertSame('Great track', $song->getComment());
        $this->assertSame('La la la', $song->getLyrics());
    }

    public function testUpdateMetadataExplicitFlag(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $this->assertFalse($song->isExplicit());

        $song->updateMetadata(explicit: true);
        $this->assertTrue($song->isExplicit());

        $song->updateMetadata(explicit: false);
        $this->assertFalse($song->isExplicit());
    }

    public function testUpdateMetadataPreservesFieldsWhenNull(): void
    {
        $song = Song::create(
            $this->albumId,
            'Title',
            '/path.mp3',
            1024,
            'audio/mpeg',
            track: 1,
            disc: 1,
            year: 1990,
        );

        $song->updateMetadata();

        $this->assertSame('Title', $song->getTitle());
        $this->assertSame(1, $song->getTrack());
        $this->assertSame(1, $song->getDisc());
        $this->assertSame(1990, $song->getYear());
    }

    public function testUpdateMetadataThrowsOnEmptyTitle(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Song title cannot be empty.');

        $song->updateMetadata(title: '');
    }

    public function testUpdateMetadataThrowsOnWhitespaceOnlyTitle(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Song title cannot be empty.');

        $song->updateMetadata(title: '   ');
    }

    public function testUpdateMetadataSetsUpdatedAt(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $before = $song->getUpdatedAt();

        $song->updateMetadata(track: 2);

        $this->assertNotEquals($before, $song->getUpdatedAt());
    }

    public function testUpdateAudioMetadata(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->updateAudioMetadata(
            size: 2048,
            mimeType: 'audio/flac',
            length: 200.0,
            hash: 'sha256:newhash',
            bitrate: 320,
            sampleRate: 48000,
            channels: 2,
            codec: 'FLAC',
        );

        $this->assertSame(2048, $song->getSize());
        $this->assertSame('audio/flac', $song->getMimeType());
        $this->assertSame(200.0, $song->getLength());
        $this->assertSame('sha256:newhash', $song->getHash());
        $this->assertSame(320, $song->getBitrate());
        $this->assertSame(48000, $song->getSampleRate());
        $this->assertSame(2, $song->getChannels());
        $this->assertSame('FLAC', $song->getCodec());
    }

    public function testUpdateAudioMetadataPreservesWhenNull(): void
    {
        $song = Song::create(
            $this->albumId,
            'Title',
            '/path.mp3',
            1024,
            'audio/mpeg',
            length: 180.0,
            bitrate: 128,
        );

        $song->updateAudioMetadata();

        $this->assertSame(180.0, $song->getLength());
        $this->assertSame(128, $song->getBitrate());
    }

    public function testUpdateAudioMetadataSetsUpdatedAt(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $before = $song->getUpdatedAt();

        $song->updateAudioMetadata(bitrate: 320);

        $this->assertNotEquals($before, $song->getUpdatedAt());
    }

    public function testUpdateAudioFeatures(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->updateAudioFeatures(
            energy: 0.9,
            danceability: 0.7,
            valence: 0.5,
            acousticness: 0.1,
            instrumentalness: 0.0,
            liveness: 0.15,
            spechiness: 0.04,
            loudness: -4.2,
        );

        $this->assertSame(0.9, $song->getEnergy());
        $this->assertSame(0.7, $song->getDanceability());
        $this->assertSame(0.5, $song->getValence());
        $this->assertSame(0.1, $song->getAcousticness());
        $this->assertSame(0.0, $song->getInstrumentalness());
        $this->assertSame(0.15, $song->getLiveness());
        $this->assertSame(0.04, $song->getSpechiness());
        $this->assertSame(-4.2, $song->getLoudness());
    }

    public function testUpdateAudioFeaturesPreservesWhenNull(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->updateAudioFeatures(energy: 0.8, danceability: 0.6);

        $song->updateAudioFeatures(loudness: -5.0);

        $this->assertSame(0.8, $song->getEnergy());
        $this->assertSame(0.6, $song->getDanceability());
        $this->assertSame(-5.0, $song->getLoudness());
    }

    public function testUpdateAudioFeaturesSetsUpdatedAt(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $before = $song->getUpdatedAt();

        $song->updateAudioFeatures(energy: 0.5);

        $this->assertNotEquals($before, $song->getUpdatedAt());
    }

    public function testUpdateExternalIds(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->updateExternalIds(
            mbid: 'song-mbid',
            discogsId: 'song-discogs',
            spotifyId: 'song-spotify',
        );

        $this->assertSame('song-mbid', $song->getMbid());
        $this->assertSame('song-discogs', $song->getDiscogsId());
        $this->assertSame('song-spotify', $song->getSpotifyId());
    }

    public function testUpdateExternalIdsPreservesWhenNull(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->updateExternalIds(mbid: 'existing-mbid');

        $song->updateExternalIds(spotifyId: 'new-spotify');

        $this->assertSame('existing-mbid', $song->getMbid());
        $this->assertSame('new-spotify', $song->getSpotifyId());
    }

    public function testUpdateExternalIdsSetsUpdatedAt(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $before = $song->getUpdatedAt();

        $song->updateExternalIds(mbid: 'mbid');

        $this->assertNotEquals($before, $song->getUpdatedAt());
    }

    public function testLockField(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->lockField('title');

        $this->assertTrue($song->isFieldLocked('title'));
        $this->assertFalse($song->isFieldLocked('track'));
    }

    public function testLockFieldIsIdempotent(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->lockField('title');
        $before = $song->getUpdatedAt();

        $song->lockField('title');

        $this->assertSame($before, $song->getUpdatedAt());
    }

    public function testUnlockField(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->lockField('title');
        $song->unlockField('title');

        $this->assertFalse($song->isFieldLocked('title'));
    }

    public function testUnlockFieldSetsUpdatedAt(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->lockField('title');
        $before = $song->getUpdatedAt();
        $song->unlockField('title');

        $this->assertNotEquals($before, $song->getUpdatedAt());
    }

    public function testUpdateMetadataThrowsOnLockedTitle(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->lockField('title');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "title" is locked and cannot be updated.');

        $song->updateMetadata(title: 'New Title');
    }

    public function testUpdateMetadataAllowsOtherFieldsWhenTitleLocked(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');
        $song->lockField('title');
        $song->updateMetadata(track: 3);

        $this->assertSame('Title', $song->getTitle());
        $this->assertSame(3, $song->getTrack());
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');

        $this->assertInstanceOf(Uuid::class, $song->getId());
        $this->assertInstanceOf(PublicId::class, $song->getPublicId());
        $this->assertInstanceOf(Uuid::class, $song->getAlbumId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $song->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $song->getUpdatedAt());
    }

    public function testCreatedAtAndUpdatedAtAreCloseOnCreation(): void
    {
        $song = Song::create($this->albumId, 'Title', '/path.mp3', 1024, 'audio/mpeg');

        $diff = $song->getUpdatedAt()->getTimestamp() - $song->getCreatedAt()->getTimestamp();
        $this->assertSame(0, $diff, 'createdAt and updatedAt should have the same second on creation.');
    }
}
