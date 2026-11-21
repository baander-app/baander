<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Tests\Reader;

use Baander\MediaMetadata\Reader\AsfReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AsfReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            unlink($file);
        }
    }

    public function testReadsContentExtendedTagsPicturesAndAudioPropertiesWithoutChangingFile(): void
    {
        $strings = array_map(self::wide(...), ['Bjørk song', 'Bjørk', '', 'A comment', '']);
        $content = pack('v*', ...array_map(strlen(...), $strings)) . implode('', $strings);
        $tags = [
            ['WM/AlbumTitle', 0, self::wide('Album')],
            ['WM/AlbumArtist', 0, self::wide('Various Artists')],
            ['WM/TrackNumber', 3, pack('V', 4)],
            ['WM/PartOfSet', 0, self::wide('2/3')],
            ['WM/Year', 0, self::wide('2026-11-21')],
            ['WM/Genre', 0, self::wide('Electronic')],
            ['WM/Composer', 0, self::wide('Composer')],
            ['WM/BeatsPerMinute', 0, self::wide('123')],
            ['MusicBrainz/Track Id', 0, self::wide('recording-id')],
            ['MusicBrainz/Album Id', 0, self::wide('album-id')],
            ['MusicBrainz/Artist Id', 0, self::wide('artist-one')],
            ['MusicBrainz/Artist Id', 0, self::wide('artist-two')],
            ['MusicBrainz/Album Artist Id', 0, self::wide('album-artist-id')],
            ['ARTISTS', 0, self::wide('Bjørk')],
            ['ARTISTS', 0, self::wide('Guest')],
            ['WM/Picture', 1, chr(3) . pack('V', 3) . self::wide('image/png') . self::wide('Front') . 'png'],
        ];
        $extended = pack('v', count($tags));
        foreach ($tags as [$name, $type, $value]) {
            $name = self::wide($name);
            $extended .= pack('v', strlen($name)) . $name . pack('vv', $type, strlen($value)) . $value;
        }
        $properties = str_repeat("\0", 40) . pack('P', 65_000_000) . pack('P', 0) . pack('P', 1500) . pack('V4', 0, 1, 1, 128000);
        $stream = hex2bin('409e69f84d5bcf11a8fd00805f5c442b') . str_repeat("\0", 24) . pack('VVvV', 18, 0, 1, 0) . pack('vvVVvvv', 0x161, 2, 44100, 16000, 1, 16, 0);
        $file = $this->fixture([
            self::object('3326b2758e66cf11a6d900aa0062ce6c', $content),
            self::object('40a4d0d207e3d21197f000a0c95ea850', $extended),
            self::object('a1dcab8c47a9cf118ee400c00c205365', $properties),
            self::object('9107dcb7b7a9cf118ee600c00c205365', $stream),
        ]);
        $before = hash_file('sha256', $file);
        $metadata = (new AsfReader(new NullLogger()))->read($file);
        self::assertSame('Bjørk song', $metadata->getTitle());
        self::assertSame(['Bjørk'], $metadata->getArtists());
        self::assertSame(['Bjørk', 'Guest'], $metadata->getIndividualArtists());
        self::assertSame('Album', $metadata->getAlbum());
        self::assertSame(['Various Artists'], $metadata->getAlbumArtists());
        self::assertSame(4, $metadata->getTrackNumber());
        self::assertSame(2, $metadata->getDiscNumber());
        self::assertSame(2026, $metadata->getYear());
        self::assertSame(['Electronic'], $metadata->getGenre());
        self::assertSame('Composer', $metadata->getComposer());
        self::assertSame(123, $metadata->getBpm());
        self::assertSame('A comment', $metadata->getComment());
        self::assertSame('recording-id', $metadata->getMbid());
        self::assertSame('album-id', $metadata->getMbAlbumId());
        self::assertSame(['artist-one', 'artist-two'], $metadata->getMbArtistIds());
        self::assertSame(['album-artist-id'], $metadata->getMbAlbumArtistIds());
        self::assertSame(5.0, $metadata->getDuration());
        self::assertSame(44100, $metadata->getSampleRate());
        self::assertSame(2, $metadata->getChannels());
        self::assertSame(128000, $metadata->getBitrate());
        self::assertSame('png', $metadata->getFrontCover()?->getImageData());
        self::assertSame('Front', $metadata->getFrontCover()->getDescription());
        self::assertSame($before, hash_file('sha256', $file));
    }

    public function testReadsMetadataAndLibraryRecordsInHeaderExtension(): void
    {
        $objects = [];
        foreach (['eacbf8c5af5b77488467aa8c44fa4cca', '941c23449894d149a1411d134e457054'] as $guid) {
            $name = self::wide('Author');
            $value = self::wide(count($objects) === 0 ? 'First' : 'Second');
            $records = pack('v', 1) . pack('vvvvV', 0, 0, strlen($name), 0, strlen($value)) . $name . $value;
            $objects[] = self::object($guid, $records);
        }
        $pictureName = self::wide('WM/Picture');
        $picture = chr(3) . pack('V', 70000) . self::wide('image/jpeg') . self::wide('Large cover') . str_repeat('x', 70000);
        $pictureRecord = pack('v', 1) . pack('vvvvV', 0, 0, strlen($pictureName), 1, strlen($picture)) . $pictureName . $picture;
        $objects[] = self::object('941c23449894d149a1411d134e457054', $pictureRecord);
        $extension = str_repeat("\0", 16) . pack('vV', 6, strlen(implode('', $objects))) . implode('', $objects);
        $metadata = (new AsfReader(new NullLogger()))->read($this->fixture([self::object('b503bf5f2ea9cf118ee300c00c205365', $extension)]));
        self::assertSame(['First', 'Second'], $metadata->getArtists());
        self::assertSame(70000, $metadata->getFrontCover()?->getImageSize());
    }

    public function testRejectsTruncatedOrOverflowingObjectsAndMissingFiles(): void
    {
        $reader = new AsfReader(new NullLogger());
        self::assertNull($reader->read('/missing/baander.app.wma')->getTitle());
        foreach ([hex2bin('3326b2758e66cf11a6d900aa0062ce6c') . pack('P', 0), hex2bin('3326b2758e66cf11a6d900aa0062ce6c') . str_repeat("\xff", 8)] as $object) {
            self::assertNull($reader->read($this->fixture([$object]))->getTitle());
        }
        $file = $this->fixture([self::object('40a4d0d207e3d21197f000a0c95ea850', pack('vv', 1, 500))]);
        self::assertNull($reader->read($file)->getTitle());
    }

    public function testSeeksPastLargeUnknownHeaderObjects(): void
    {
        $file = $this->fixture([]);
        $handle = fopen($file, 'wb');
        $paddingSize = 40_000_000;
        $title = self::wide('After padding');
        $content = self::object('3326b2758e66cf11a6d900aa0062ce6c', pack('v5', strlen($title), 0, 0, 0, 0) . $title);
        fwrite($handle, hex2bin('3026b2758e66cf11a6d900aa0062ce6c') . pack('P', 30 + $paddingSize + strlen($content)) . pack('VCC', 2, 1, 2));
        fwrite($handle, str_repeat("\0", 16) . pack('P', $paddingSize));
        fseek($handle, 30 + $paddingSize);
        fwrite($handle, $content);
        fclose($handle);
        self::assertSame('After padding', (new AsfReader(new NullLogger()))->read($file)->getTitle());
    }

    public function testRejectsOversizedKnownMetadataBeforeLoadingIt(): void
    {
        $file = $this->fixture([]);
        $handle = fopen($file, 'wb');
        $size = 40_000_000;
        fwrite($handle, hex2bin('3026b2758e66cf11a6d900aa0062ce6c') . pack('P', $size + 30) . pack('VCC', 1, 1, 2));
        fwrite($handle, hex2bin('40a4d0d207e3d21197f000a0c95ea850') . pack('P', $size));
        ftruncate($handle, $size + 30);
        fclose($handle);
        self::assertNull((new AsfReader(new NullLogger()))->read($file)->getTitle());
    }

    private static function wide(string $value): string
    {
        return mb_convert_encoding($value . "\0", 'UTF-16LE', 'UTF-8');
    }

    private static function object(string $guid, string $data): string
    {
        return hex2bin($guid) . pack('P', 24 + strlen($data)) . $data;
    }

    /** @param list<string> $objects */
    private function fixture(array $objects): string
    {
        $file = tempnam(sys_get_temp_dir(), 'asf-');
        $this->files[] = $file;
        $header = self::object('3026b2758e66cf11a6d900aa0062ce6c', pack('VCC', count($objects), 1, 2) . implode('', $objects));
        file_put_contents($file, $header . 'unread audio payload');
        return $file;
    }
}
