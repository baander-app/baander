<?php

declare(strict_types=1);

namespace Baander\MediaMetadata\Reader;

use Baander\MediaMetadata\Model\ExtractedMetadata;
use Baander\MediaMetadata\Parser\AsfParser;
use Psr\Log\LoggerInterface;

final class AsfReader
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function read(string $path): ExtractedMetadata
    {
        try {
            if (!is_file($path) || !is_readable($path)) {
                throw new \RuntimeException('ASF file does not exist or is not readable');
            }
            $parser = new AsfParser($path);
            $parser->parse();
            return $this->metadata($parser);
        } catch (\Throwable $error) {
            $this->logger->warning('Failed to parse ASF file', ['path' => $path, 'error' => $error->getMessage()]);
            return new ExtractedMetadata();
        }
    }

    private function metadata(AsfParser $parser): ExtractedMetadata
    {
        $fields = $parser->getFields();
        $first = static fn(string $name): ?string => $fields[$name][0] ?? null;
        $metadata = new ExtractedMetadata();
        $metadata->setTitle($first('TITLE'));
        $metadata->setAlbum($first('WM/ALBUMTITLE'));
        $metadata->setArtists($fields['AUTHOR'] ?? []);
        $metadata->setIndividualArtists($fields['ARTISTS'] ?? $fields['WM/ARTISTS'] ?? []);
        $metadata->setAlbumArtists($fields['WM/ALBUMARTIST'] ?? []);
        $metadata->setComposer($first('WM/COMPOSER'));
        $metadata->setComment($first('DESCRIPTION'));
        $metadata->setGenre($fields['WM/GENRE'] ?? []);
        $first('WM/TRACKNUMBER') |> self::positiveNumber(...) |> $metadata->setTrackNumber(...);
        $first('WM/PARTOFSET') |> self::positiveNumber(...) |> $metadata->setDiscNumber(...);
        substr($first('WM/YEAR') ?? '', 0, 4) |> self::positiveNumber(...) |> $metadata->setYear(...);
        $first('WM/BEATSPERMINUTE') |> self::positiveNumber(...) |> $metadata->setBpm(...);
        $metadata->setMbid($first('MUSICBRAINZ/TRACK ID'));
        $metadata->setMbAlbumId($first('MUSICBRAINZ/ALBUM ID'));
        $metadata->setMbArtistIds($fields['MUSICBRAINZ/ARTIST ID'] ?? []);
        $metadata->setMbAlbumArtistIds($fields['MUSICBRAINZ/ALBUM ARTIST ID'] ?? []);
        $metadata->setPictures($parser->getPictures());
        $info = $parser->getStreamInfo();
        $metadata->setDuration($info['duration'] ?? null);
        $metadata->setBitrate($info['bitrate'] ?? null);
        $metadata->setSampleRate($info['sampleRate'] ?? null);
        $metadata->setChannels($info['channels'] ?? null);
        return $metadata;
    }

    private static function positiveNumber(?string $value): ?int
    {
        $number = (int) ($value ?? '');
        return $number > 0 ? $number : null;
    }
}
