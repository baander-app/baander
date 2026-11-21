<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Parser;

use Baander\MediaMetadata\Model\CoverArt;
use Baander\MediaMetadata\Model\ExtractedMetadata;

/** Traverses metadata atoms without loading media payloads. */
final class Mp4Parser
{
    private const int MAX_VALUE_BYTES = 16777216;
    private const int MAX_METADATA_BYTES = 33554432;
    private const int MAX_ATOMS = 100000;

    private int $atomsRemaining = self::MAX_ATOMS;
    private int $bytesRemaining = self::MAX_METADATA_BYTES;

    /** @var array<string, list<string>> */
    private array $tags = [];
    /** @var list<CoverArt> */
    private array $pictures = [];
    private ExtractedMetadata $metadata;

    public function __construct(private readonly string $path)
    {
        $this->metadata = new ExtractedMetadata();
    }

    public function parse(): ExtractedMetadata
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open MP4 file.');
        }

        try {
            $size = fstat($handle)['size'] ?? 0;
            $this->walk($handle, 0, $size, 0);
        } finally {
            fclose($handle);
        }

        $this->mapTags();
        $this->metadata->setPictures($this->pictures);

        return $this->metadata;
    }

    /**
     * @param resource $handle
     *
     * @return \Generator<int, array{type: string, start: int, end: int}>
     */
    private function atoms($handle, int $start, int $end): \Generator
    {
        while ($end - $start >= 8 && $this->atomsRemaining-- > 0) {
            $header = $this->read($handle, $start, 8);
            if (strlen($header) !== 8) {
                return;
            }
            $size = self::uint32($header, 0);
            $headerSize = 8;
            if ($size === 1) {
                $extended = $this->read($handle, $start + 8, 8);
                if (strlen($extended) !== 8) {
                    return;
                }
                $high = self::uint32($extended, 0);
                $low = self::uint32($extended, 4);
                if ($high > 0x7FFFFFFF) {
                    return;
                }
                $size = ($high << 32) | $low;
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $end - $start;
            }
            if ($size < $headerSize || $size > $end - $start) {
                return;
            }

            yield ['type' => substr($header, 4, 4), 'start' => $start + $headerSize, 'end' => $start + $size];
            $start += $size;
        }
    }

    /** @param resource $handle */
    private function walk($handle, int $start, int $end, int $depth): void
    {
        if ($depth > 12) {
            return;
        }
        foreach ($this->atoms($handle, $start, $end) as $atom) {
            if (in_array($atom['type'], ['moov', 'udta'], true)) {
                $this->walk($handle, $atom['start'], $atom['end'], $depth + 1);
            } elseif ($atom['type'] === 'meta') {
                $this->walk($handle, $atom['start'] + 4, $atom['end'], $depth + 1);
            } elseif ($atom['type'] === 'ilst') {
                $this->readTags($handle, $atom['start'], $atom['end']);
            } elseif ($atom['type'] === 'trak' && $this->metadata->getSampleRate() === null) {
                $this->readTrack($handle, $atom['start'], $atom['end']);
            }
        }
    }

    /** @param resource $handle */
    private function readTags($handle, int $start, int $end): void
    {
        foreach ($this->atoms($handle, $start, $end) as $tag) {
            $name = $tag['type'];
            $namespace = null;
            $values = [];
            foreach ($this->atoms($handle, $tag['start'], $tag['end']) as $part) {
                $length = $part['end'] - $part['start'];
                if ($length > self::MAX_VALUE_BYTES || $length > $this->bytesRemaining) {
                    continue;
                }
                if (!in_array($part['type'], ['mean', 'name', 'data'], true)) {
                    continue;
                }
                $this->bytesRemaining -= $length;
                $data = $this->read($handle, $part['start'], $length);
                if ($part['type'] === 'mean' && strlen($data) >= 4) {
                    $namespace = substr($data, 4);
                } elseif ($part['type'] === 'name' && strlen($data) >= 4) {
                    $name = strtoupper(substr($data, 4));
                } elseif ($part['type'] === 'data' && strlen($data) >= 8) {
                    $values[] = ['type' => self::uint32($data, 0) & 0xFFFFFF, 'value' => substr($data, 8)];
                }
            }
            if ($tag['type'] === '----' && $namespace !== 'com.apple.iTunes') {
                continue;
            }
            foreach ($values as $value) {
                $this->readValue($name, $value['type'], $value['value']);
            }
        }
    }

    private function readValue(string $name, int $type, string $value): void
    {
        if ($name === 'covr') {
            $mime = match ($type) {13 => 'image/jpeg', 14 => 'image/png', default => null};
            if ($mime !== null && $value !== '') {
                $this->pictures[] = new CoverArt(CoverArt::TYPE_COVER_FRONT, $mime, '', $value);
            }
        } elseif (in_array($name, ['trkn', 'disk'], true) && strlen($value) >= 6) {
            $number = unpack('n', substr($value, 2, 2))[1];
            if ($number > 0) {
                $name === 'trkn' ? $this->metadata->setTrackNumber($number) : $this->metadata->setDiscNumber($number);
            }
        } elseif ($name === 'tmpo' && strlen($value) === 2) {
            $this->metadata->setBpm(unpack('n', $value)[1]);
        } elseif (in_array($type, [0, 1, 2], true)) {
            $text = $type === 2 ? mb_convert_encoding($value, 'UTF-8', 'UTF-16BE') : mb_scrub($value, 'UTF-8');
            $text = trim($text, " \t\n\r\0\x0B");
            if ($text !== '') {
                $this->tags[$name][] = $text;
            }
        }
    }

    private function mapTags(): void
    {
        $this->metadata->setTitle($this->tags["\xA9nam"][0] ?? null);
        $this->metadata->setAlbum($this->tags["\xA9alb"][0] ?? null);
        $this->metadata->setArtists($this->tags["\xA9ART"] ?? []);
        $this->metadata->setAlbumArtists($this->tags['aART'] ?? []);
        $this->metadata->setIndividualArtists($this->tags['ARTISTS'] ?? []);
        $this->metadata->setComposer($this->tags["\xA9wrt"][0] ?? null);
        $this->metadata->setComment($this->tags["\xA9cmt"][0] ?? $this->tags['desc'][0] ?? null);
        $this->metadata->setGenre($this->tags["\xA9gen"] ?? []);
        $year = (int) substr($this->tags["\xA9day"][0] ?? '', 0, 4);
        if ($year > 0) {
            $this->metadata->setYear($year);
        }
        $this->metadata->setMbid($this->tags['MUSICBRAINZ TRACK ID'][0] ?? null);
        $this->metadata->setMbAlbumId($this->tags['MUSICBRAINZ ALBUM ID'][0] ?? null);
        $this->metadata->setMbArtistIds($this->tags['MUSICBRAINZ ARTIST ID'] ?? []);
        $this->metadata->setMbAlbumArtistIds($this->tags['MUSICBRAINZ ALBUM ARTIST ID'] ?? []);
    }

    /** @param resource $handle */
    private function readTrack($handle, int $start, int $end): void
    {
        foreach ($this->atoms($handle, $start, $end) as $atom) {
            if ($atom['type'] !== 'mdia') {
                continue;
            }
            $handler = '';
            $duration = null;
            $sample = null;
            foreach ($this->atoms($handle, $atom['start'], $atom['end']) as $child) {
                if ($child['type'] === 'hdlr' && $child['end'] - $child['start'] >= 12) {
                    $handler = $this->read($handle, $child['start'] + 8, 4);
                } elseif ($child['type'] === 'mdhd') {
                    $duration = $this->duration($handle, $child['start'], $child['end']);
                } elseif ($child['type'] === 'minf') {
                    $sample = $child;
                }
            }
            if ($handler === 'soun') {
                if ($duration !== null) {
                    $this->metadata->setDuration($duration);
                }
                if ($sample !== null) {
                    $this->readSampleTable($handle, $sample['start'], $sample['end']);
                }
            }
        }
    }

    /** @param resource $handle */
    private function duration($handle, int $start, int $end): ?float
    {
        $data = $this->read($handle, $start, min(32, $end - $start));
        $version = $data[0] ?? '';
        if ($version === "\0" && strlen($data) >= 20) {
            $scale = self::uint32($data, 12);
            $duration = self::uint32($data, 16);
            if ($duration === 0xFFFFFFFF) {
                return null;
            }
        } elseif ($version === "\1" && strlen($data) >= 32) {
            $scale = self::uint32($data, 20);
            $high = self::uint32($data, 24);
            $low = self::uint32($data, 28);
            if ($high === 0xFFFFFFFF && $low === 0xFFFFFFFF) {
                return null;
            }
            $duration = $high * 4294967296.0 + $low;
        } else {
            return null;
        }

        return $scale > 0 ? (float) ($duration / $scale) : null;
    }

    /** @param resource $handle */
    private function readSampleTable($handle, int $start, int $end, int $depth = 0): void
    {
        if ($depth > 3) {
            return;
        }
        foreach ($this->atoms($handle, $start, $end) as $atom) {
            if ($atom['type'] === 'stbl') {
                $this->readSampleTable($handle, $atom['start'], $atom['end'], $depth + 1);
            } elseif ($atom['type'] === 'stsd' && $atom['end'] - $atom['start'] >= 8) {
                foreach ($this->atoms($handle, $atom['start'] + 8, $atom['end']) as $entry) {
                    if (in_array($entry['type'], ['mp4a', 'alac'], true)) {
                        $this->readAudioEntry($handle, $entry['start'], $entry['end']);
                        return;
                    }
                }
            }
        }
    }

    /** @param resource $handle */
    private function readAudioEntry($handle, int $start, int $end): void
    {
        if ($end - $start < 28) {
            return;
        }
        $data = $this->read($handle, $start, 28);
        $channels = unpack('n', substr($data, 16, 2))[1];
        $rate = self::uint32($data, 24) >> 16;
        if ($channels > 0) {
            $this->metadata->setChannels($channels);
        }
        if ($rate > 0) {
            $this->metadata->setSampleRate($rate);
        }
        $version = unpack('n', substr($data, 8, 2))[1];
        $extension = match ($version) {0 => 28, 1 => 44, 2 => 64, default => $end - $start};
        foreach ($this->atoms($handle, $start + $extension, $end) as $atom) {
            $length = $atom['end'] - $atom['start'];
            if ($atom['type'] === 'alac' && $length >= 28) {
                $config = $this->read($handle, $atom['start'], 28);
                $this->metadata->setChannels(ord($config[13]));
                $this->metadata->setSampleRate(self::uint32($config, 24));
                $this->setBitrate(self::uint32($config, 20));
            } elseif ($atom['type'] === 'btrt' && $length >= 12) {
                $this->setBitrate(self::uint32($this->read($handle, $atom['start'], 12), 8));
            } elseif ($atom['type'] === 'esds' && $length >= 4 && $length <= 65536) {
                $this->readDescriptors($this->read($handle, $atom['start'] + 4, $length - 4));
            }
        }
    }

    private function readDescriptors(string $data, int $depth = 0): void
    {
        if ($depth > 4) {
            return;
        }
        $offset = 0;
        $end = strlen($data);
        while ($offset + 2 <= $end) {
            $tag = ord($data[$offset++]);
            $length = 0;
            for ($i = 0; $i < 4; ++$i) {
                if ($offset >= $end) {
                    return;
                }
                $byte = ord($data[$offset++]);
                $length = ($length << 7) | ($byte & 0x7F);
                if (($byte & 0x80) === 0) {
                    break;
                }
            }
            if (($byte & 0x80) !== 0 || $length > $end - $offset) {
                return;
            }
            $payload = substr($data, $offset, $length);
            if ($tag === 3 && $length >= 3) {
                $flags = ord($payload[2]);
                $skip = 3 + (($flags & 0x80) !== 0 ? 2 : 0);
                if (($flags & 0x40) !== 0) {
                    if ($skip >= $length) {
                        return;
                    }
                    $skip += 1 + ord($payload[$skip]);
                }
                $skip += ($flags & 0x20) !== 0 ? 2 : 0;
                $this->readDescriptors(substr($payload, $skip), $depth + 1);
            } elseif ($tag === 4 && $length >= 13) {
                $this->setBitrate(self::uint32($payload, 9));
                $this->readDescriptors(substr($payload, 13), $depth + 1);
            } elseif ($tag === 5 && $length >= 2) {
                $this->readAacConfig($payload);
            }
            $offset += $length;
        }
    }

    private function readAacConfig(string $data): void
    {
        $bits = '';
        foreach (str_split(substr($data, 0, 8)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $offset = bindec(substr($bits, 0, 5)) === 31 ? 11 : 5;
        $index = bindec(substr($bits, $offset, 4));
        $offset += 4;
        $rates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
        if ($index === 15) {
            if (strlen($bits) < $offset + 28) {
                return;
            }
            $rate = bindec(substr($bits, $offset, 24));
            $offset += 24;
        } else {
            $rate = $rates[$index] ?? 0;
        }
        if (strlen($bits) < $offset + 4) {
            return;
        }
        $channels = bindec(substr($bits, $offset, 4));
        if ($rate > 0) {
            $this->metadata->setSampleRate($rate);
        }
        if ($channels >= 1 && $channels <= 7) {
            $this->metadata->setChannels($channels === 7 ? 8 : $channels);
        }
    }

    private function setBitrate(int $bitrate): void
    {
        if ($bitrate > 0) {
            $this->metadata->setBitrate($bitrate);
        }
    }

    /** @param resource $handle */
    private function read($handle, int $offset, int $length): string
    {
        if ($length <= 0 || fseek($handle, $offset) !== 0) {
            return '';
        }
        $data = fread($handle, $length);

        return $data === false ? '' : $data;
    }

    private static function uint32(string $data, int $offset): int
    {
        return unpack('N', substr($data, $offset, 4))[1];
    }
}
