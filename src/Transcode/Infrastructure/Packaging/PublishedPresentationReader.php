<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Packaging;

use App\Transcode\Application\DTO\MediaTrack;
use DOMElement;
use DOMXPath;
use UnexpectedValueException;

/** Reads one atomic native index; only closed generation-local files are published. */
final class PublishedPresentationReader
{
    private const string NS = 'urn:mpeg:dash:schema:mpd:2011';
    private const int MAX_BYTES = 4_194_304;
    private const int MAX_SEGMENTS = 10_000;

    public function read(string $directory): ?PublishedPresentation
    {
        $path = rtrim($directory, '/') . '/manifest.mpd';
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return null;
        }
        try {
            $source = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if ($source === false || strlen($source) > self::MAX_BYTES || stripos($source, '<!DOCTYPE') !== false) {
            return null;
        }
        $xml = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$xml->loadXML($source, LIBXML_NONET) || $xml->doctype !== null) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $xml->documentElement;
        if ($root === null || $root->localName !== 'MPD' || $root->namespaceURI !== self::NS
            || !in_array($root->getAttribute('type'), ['dynamic', 'static'], true)) {
            return null;
        }
        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('m', self::NS);
        $periods = $xpath->query('/m:MPD/m:Period');
        if ($periods === false || $periods->length !== 1 || !($periods->item(0) instanceof DOMElement)) {
            return null;
        }
        $period = $periods->item(0);
        $representations = $xpath->query('m:AdaptationSet/m:Representation', $period);
        if ($representations === false || $representations->length < 1 || $representations->length > 2) {
            return null;
        }
        try {
            $tracks = [];
            foreach ($representations as $representation) {
                if (!$representation instanceof DOMElement || !$representation->parentNode instanceof DOMElement) {
                    return null;
                }
                $adaptation = $representation->parentNode;
                $kind = MediaTrack::tryFrom($adaptation->getAttribute('contentType'));
                if ($kind === null || isset($tracks[$kind->value])) {
                    return null;
                }
                $id = $kind === MediaTrack::Video ? '0' : '1';
                if ($representation->getAttribute('id') !== $id
                    || $representation->getAttribute('mimeType') !== $kind->value . '/mp4') {
                    return null;
                }
                $templates = $xpath->query('m:SegmentTemplate', $representation);
                if ($templates === false || $templates->length !== 1 || !$templates->item(0) instanceof DOMElement) {
                    return null;
                }
                $template = $templates->item(0);
                if ($template->getAttribute('initialization') !== 'init-$RepresentationID$.mp4'
                    || $template->getAttribute('media') !== 'track-$RepresentationID$-seg-$Number%05d$.m4s') {
                    return null;
                }
                $timescale = $this->integer($template->getAttribute('timescale'), 1);
                $offset = $this->integer($template->getAttribute('presentationTimeOffset') ?: '0');
                $number = $this->integer($template->getAttribute('startNumber') ?: '1', 1);
                $points = $xpath->query('m:SegmentTimeline/m:S', $template);
                if ($points === false || $points->length === 0) {
                    return null;
                }
                $segments = [];
                $end = 0;
                foreach ($points as $point) {
                    if (!$point instanceof DOMElement) {
                        return null;
                    }
                    $start = $point->hasAttribute('t') ? $this->integer($point->getAttribute('t')) : $end;
                    $duration = $this->integer($point->getAttribute('d'), 1);
                    $repeat = $this->integer($point->getAttribute('r') ?: '0');
                    if ($start < $end || $repeat >= self::MAX_SEGMENTS || count($segments) + $repeat + 1 > self::MAX_SEGMENTS
                        || $duration > intdiv(PHP_INT_MAX - $start, $repeat + 1) || $number > PHP_INT_MAX - $repeat - 1) {
                        return null;
                    }
                    for ($index = 0; $index <= $repeat; ++$index) {
                        $file = sprintf('track-%s-seg-%05d.m4s', $id, $number);
                        if (!$this->completeFile($directory . '/' . $file, ['moof', 'mdat'])) {
                            return null;
                        }
                        $segments[] = new PublishedSegment(count($segments), $number++, $start, $duration, $file);
                        $start += $duration;
                    }
                    $end = $start;
                }
                $init = 'init-' . $id . '.mp4';
                if (!$this->completeFile($directory . '/' . $init, ['ftyp', 'moov'])) {
                    return null;
                }
                $codec = $representation->getAttribute('codecs');
                if ($codec === '' || strlen($codec) > 128 || str_contains($codec, ',')) {
                    return null;
                }
                $tracks[$kind->value] = new PublishedTrack($kind, $id, $codec, $timescale, $offset, $init, $segments,
                    $this->attributes($representation, ['id', 'mimeType', 'codecs', 'bandwidth', 'width', 'height', 'sar', 'frameRate', 'audioSamplingRate']),
                    $this->attributes($adaptation, ['id', 'contentType', 'startWithSAP', 'segmentAlignment', 'bitstreamSwitching', 'frameRate', 'maxWidth', 'maxHeight', 'par', 'lang']));
            }
            if (!isset($tracks[MediaTrack::Video->value])) {
                return null;
            }
            return new PublishedPresentation($root->getAttribute('type') === 'static',
                $this->attributes($root, ['type', 'profiles', 'mediaPresentationDuration', 'minimumUpdatePeriod', 'suggestedPresentationDelay', 'availabilityStartTime', 'publishTime', 'maxSegmentDuration', 'minBufferTime', 'timeShiftBufferDepth']),
                $this->attributes($period, ['id', 'start', 'duration']), $tracks);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    private function integer(string $value, int $minimum = 0): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < $minimum) {
            throw new UnexpectedValueException('Invalid native media timeline integer.');
        }
        return $parsed;
    }

    /**
     * @param list<string> $names
     * @return array<string,string>
     */
    private function attributes(DOMElement $element, array $names): array
    {
        $attributes = [];
        foreach ($names as $name) {
            if ($element->hasAttribute($name)) {
                $value = $element->getAttribute($name);
                if (strlen($value) > 256) {
                    throw new UnexpectedValueException('Native media attribute exceeds its limit.');
                }
                $attributes[$name] = $value;
            }
        }
        return $attributes;
    }

    private function nonemptyFile(string $path): bool
    {
        clearstatcache(true, $path);
        return !is_link($path) && is_file($path) && filesize($path) > 0;
    }

    /** @param list<string> $required */
    private function completeFile(string $path, array $required): bool
    {
        if (!$this->nonemptyFile($path)) {
            return false;
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return false;
        }
        try {
            $stat = fstat($stream);
            $offset = 0;
            $found = [];
            $boxes = 0;
            while ($stat !== false && $offset < $stat['size']) {
                if (++$boxes > 1024) {
                    return false;
                }
                $header = fread($stream, 8);
                if ($header === false || strlen($header) !== 8) {
                    return false;
                }
                $length = unpack('Nlength', substr($header, 0, 4));
                if ($length === false || $length['length'] < 8 || $length['length'] > $stat['size'] - $offset) {
                    return false;
                }
                $found[substr($header, 4, 4)] = true;
                $offset += $length['length'];
                if (fseek($stream, $offset) !== 0) {
                    return false;
                }
            }
            foreach ($required as $type) {
                if (!isset($found[$type])) {
                    return false;
                }
            }
            return true;
        } finally {
            fclose($stream);
        }
    }
}
