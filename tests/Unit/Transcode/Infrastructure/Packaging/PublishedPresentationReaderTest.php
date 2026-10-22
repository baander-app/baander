<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Packaging;

use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PublishedPresentationReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-published-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        foreach ([0, 1] as $track) {
            file_put_contents($this->directory . '/init-' . $track . '.mp4', pack('Na4', 8, 'ftyp') . pack('Na4', 8, 'moov'));
            foreach ([1, 2, 3] as $number) {
                file_put_contents($this->directory . sprintf('/track-%d-seg-%05d.m4s', $track, $number), pack('Na4', 8, 'moof') . pack('Na4', 9, 'mdat') . 'x');
            }
        }
        $this->write($this->manifest());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testExpandsNativeNumbersAndRetainsIndependentTrackTimelines(): void
    {
        $presentation = (new PublishedPresentationReader())->read($this->directory);
        self::assertNotNull($presentation);
        self::assertFalse($presentation->complete);
        $video = $presentation->track(MediaTrack::Video);
        $audio = $presentation->track(MediaTrack::Audio);
        self::assertNotNull($video);
        self::assertNotNull($audio);
        self::assertSame([0, 1, 2], array_column($video->segments, 'ordinal'));
        self::assertSame([1, 2, 3], array_column($video->segments, 'nativeNumber'));
        self::assertSame([120, 720, 1320], array_column($video->segments, 'start'));
        self::assertSame([600, 600, 200], array_column($video->segments, 'duration'));
        self::assertSame([0, 288768, 576512], array_column($audio->segments, 'start'));
        self::assertSame([288768, 287744, 96256], array_column($audio->segments, 'duration'));
        self::assertSame(120, $video->presentationTimeOffset);
        self::assertSame(100, $video->timescale);
        self::assertSame(48000, $audio->timescale);
        self::assertEqualsWithDelta(14.0, $video->end(), 0.000001);
        self::assertEqualsWithDelta(14.016, $audio->end(), 0.000001);
        self::assertTrue($presentation->covers(0.0, true));
        self::assertTrue($presentation->covers(13.99, true));
        self::assertFalse($presentation->covers(14.0, true));
        self::assertFalse($presentation->covers(14.0, true, 14.0), 'A live presentation cannot advertise completed-source EOF.');
        $this->write(str_replace('type="dynamic"', 'type="static"', $this->manifest()));
        self::assertTrue((new PublishedPresentationReader())->read($this->directory)?->complete);
    }

    public function testCompletedSourceEndUsesEachTracksOwnFinalTick(): void
    {
        $this->write(str_replace(['type="dynamic"', 'd="96256"'], ['type="static"', 'd="95000"'], $this->manifest()));
        $presentation = (new PublishedPresentationReader())->read($this->directory);
        self::assertNotNull($presentation);
        self::assertFalse($presentation->covers(14.0, true));
        self::assertTrue($presentation->covers(14.0, true, 14.0));
        self::assertFalse($presentation->covers(13.99, true, 14.0), 'Ordinary positions must retain exact track coverage.');
        self::assertFalse($presentation->covers(14.01, true, 14.0));
        self::assertFalse($presentation->covers(15.0, true, 15.0), 'A completed publication cannot cover an EOF beyond its actual media tail.');
        $xml = new \DOMDocument();
        self::assertTrue($xml->loadXML($this->manifest()));
        $xml->documentElement?->setAttribute('type', 'static');
        $adaptations = $xml->getElementsByTagName('AdaptationSet');
        $audio = $adaptations->item(1);
        self::assertNotNull($audio);
        $audio->parentNode?->removeChild($audio);
        $this->write($xml->saveXML());
        $videoOnly = (new PublishedPresentationReader())->read($this->directory);
        self::assertNotNull($videoOnly);
        self::assertFalse($videoOnly->covers(14.0, true, 14.0), 'EOF must never invent a missing audio track.');
        self::assertTrue($videoOnly->covers(14.0, false, 14.0));
    }

    #[DataProvider('unpublishedFiles')]
    public function testRejectsUnpublishedOrIncompleteFiles(string $file, ?string $replacement): void
    {
        $path = $this->directory . '/' . $file;
        if ($replacement === null) {
            unlink($path);
        } else {
            file_put_contents($path, $replacement);
        }
        self::assertNull((new PublishedPresentationReader())->read($this->directory));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function unpublishedFiles(): iterable
    {
        yield 'missing init' => ['init-0.mp4', null];
        yield 'truncated init header' => ['init-0.mp4', 'ftyp'];
        yield 'truncated init body' => ['init-0.mp4', pack('Na4', 64, 'ftyp')];
        yield 'missing moov' => ['init-0.mp4', pack('Na4', 8, 'ftyp')];
        yield 'missing video fragment' => ['track-0-seg-00002.m4s', null];
        yield 'truncated fragment header' => ['track-0-seg-00001.m4s', 'moof'];
        yield 'truncated fragment body' => ['track-0-seg-00001.m4s', pack('Na4', 100, 'moof')];
        yield 'truncated media data' => ['track-0-seg-00001.m4s', pack('Na4', 8, 'moof') . pack('Na4', 20, 'mdat') . 'x'];
        yield 'missing media data' => ['track-0-seg-00001.m4s', pack('Na4', 8, 'moof')];
        yield 'empty audio fragment' => ['track-1-seg-00003.m4s', ''];
    }

    public function testRejectsSymlinkedIndexInitAndMedia(): void
    {
        foreach (['manifest.mpd', 'init-0.mp4', 'track-0-seg-00001.m4s'] as $file) {
            $path = $this->directory . '/' . $file;
            rename($path, $path . '.closed');
            symlink($path . '.closed', $path);
            self::assertNull((new PublishedPresentationReader())->read($this->directory));
            unlink($path);
            rename($path . '.closed', $path);
        }
    }

    #[DataProvider('invalidIndexes')]
    public function testRejectsUnsafeOrUnboundedIndexes(string $search, string $replacement): void
    {
        $this->write(str_replace($search, $replacement, $this->manifest()));
        self::assertNull((new PublishedPresentationReader())->read($this->directory));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidIndexes(): iterable
    {
        yield 'parent media traversal' => ['track-$RepresentationID$-seg-$Number%05d$.m4s', '../track-$RepresentationID$-seg-$Number%05d$.m4s'];
        yield 'absolute init' => ['init-$RepresentationID$.mp4', '/init-$RepresentationID$.mp4'];
        yield 'remote media' => ['track-$RepresentationID$-seg-$Number%05d$.m4s', 'http://baander.app/$Number$.m4s'];
        yield 'unsupported template' => ['$Number%05d$', '$Time$'];
        yield 'negative repeat' => ['r="1"', 'r="-1"'];
        yield 'unbounded repeat' => ['r="1"', 'r="10000"'];
        yield 'overflow repeat' => ['r="1"', 'r="999999999999999999999999"'];
        yield 'zero timescale' => ['timescale="100"', 'timescale="0"'];
        yield 'overflow duration' => ['d="600"', 'd="9223372036854775807"'];
        yield 'overflow start' => ['t="120"', 't="99999999999999999999999"'];
        yield 'overlapping timeline' => ['<S d="200"/>', '<S t="100" d="200"/>'];
        yield 'malformed xml' => ['</MPD>', ''];
        yield 'doctype' => ['<MPD ', '<!DOCTYPE MPD [<!ENTITY external SYSTEM "file:///does-not-exist">]><MPD '];
        yield 'non-native representation' => ['id="0" mimeType', 'id="2" mimeType'];
    }

    public function testRejectsOversizeIndex(): void
    {
        $this->write(str_repeat(' ', 4_194_305));
        self::assertNull((new PublishedPresentationReader())->read($this->directory));
    }

    private function write(string $xml): void
    {
        file_put_contents($this->directory . '/manifest.mpd', $xml);
    }

    private function manifest(): string
    {
        return <<<'XML'
<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="dynamic"><Period id="0" start="PT0S">
<AdaptationSet contentType="video"><Representation id="0" mimeType="video/mp4" codecs="avc1.64000a" bandwidth="100000">
<SegmentTemplate timescale="100" presentationTimeOffset="120" startNumber="1" initialization="init-$RepresentationID$.mp4" media="track-$RepresentationID$-seg-$Number%05d$.m4s"><SegmentTimeline><S t="120" d="600" r="1"/><S d="200"/></SegmentTimeline></SegmentTemplate>
</Representation></AdaptationSet>
<AdaptationSet contentType="audio"><Representation id="1" mimeType="audio/mp4" codecs="mp4a.40.2" bandwidth="128000">
<SegmentTemplate timescale="48000" startNumber="1" initialization="init-$RepresentationID$.mp4" media="track-$RepresentationID$-seg-$Number%05d$.m4s"><SegmentTimeline><S t="0" d="288768"/><S d="287744"/><S d="96256"/></SegmentTimeline></SegmentTemplate>
</Representation></AdaptationSet></Period></MPD>
XML;
    }
}
