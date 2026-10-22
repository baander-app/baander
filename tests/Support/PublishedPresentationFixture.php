<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Small structurally complete native publication for lifecycle tests, without encoding. */
final class PublishedPresentationFixture
{
    public static function write(string $directory, int $count = 1, bool $audio = false, bool $complete = true, int $startNumber = 1): void
    {
        $adaptations = '';
        foreach ($audio ? ['video', 'audio'] : ['video'] as $id => $kind) {
            file_put_contents($directory . '/init-' . $id . '.mp4', self::box('ftyp', 'isom') . self::box('moov', 'fixture'));
            for ($index = 0; $index < $count; ++$index) {
                file_put_contents($directory . sprintf('/track-%d-seg-%05d.m4s', $id, $startNumber + $index), self::fragment());
            }
            $codec = $kind === 'video' ? 'avc1.64001f' : 'mp4a.40.2';
            $adaptations .= '<AdaptationSet contentType="' . $kind . '"><Representation id="' . $id . '" mimeType="' . $kind . '/mp4" codecs="' . $codec . '">'
                . '<SegmentTemplate timescale="1000" startNumber="' . $startNumber . '" initialization="init-$RepresentationID$.mp4" media="track-$RepresentationID$-seg-$Number%05d$.m4s">'
                . '<SegmentTimeline><S t="0" d="6000" r="' . ($count - 1) . '"/></SegmentTimeline></SegmentTemplate></Representation></AdaptationSet>';
        }
        file_put_contents($directory . '/manifest.mpd', '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="' . ($complete ? 'static' : 'dynamic') . '"' . ($complete ? '' : ' availabilityStartTime="2026-10-22T00:00:00Z" publishTime="2026-10-22T00:00:06Z" minimumUpdatePeriod="PT6S"') . '><Period>' . $adaptations . '</Period></MPD>');
    }

    public static function fragment(): string
    {
        return self::box('moof', 'fixture') . self::box('mdat', 'samples');
    }

    private static function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)) . $type . $payload;
    }
}
