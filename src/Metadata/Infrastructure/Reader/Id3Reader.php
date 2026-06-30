<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Reader;

use App\Metadata\Domain\Model\CoverArt;
use App\Metadata\Domain\Model\ExtractedMetadata;
use Psr\Log\LoggerInterface;

/**
 * Reads ID3v2 and ID3v1 tags by parsing the binary file directly.
 *
 * Uses Id3Parser for native tag extraction — no PHP id3 extension required.
 */
final class Id3Reader
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function read(string $path): ExtractedMetadata
    {
        if (!is_file($path) || !is_readable($path)) {
            $this->logger->warning('ID3 file does not exist or is not readable', ['path' => $path]);

            return new ExtractedMetadata();
        }

        try {
            $parser = new Id3Parser($path, $this->logger);
            $parser->parse();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to parse ID3 file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return new ExtractedMetadata();
        }

        return $this->mapToMetadata($parser);
    }

    private function mapToMetadata(Id3Parser $parser): ExtractedMetadata
    {
        $metadata = new ExtractedMetadata();
        $tags = $parser->getTags();

        $metadata->setTitle($this->first($tags, 'TITLE'));
        $metadata->setArtist($this->first($tags, 'ARTIST'));
        $metadata->setAlbum($this->first($tags, 'ALBUM'));
        $metadata->setAlbumArtist($this->first($tags, 'ALBUMARTIST'));
        $metadata->setComposer($this->first($tags, 'COMPOSER'));
        $metadata->setComment($this->first($tags, 'COMMENT'));

        $genre = $this->first($tags, 'GENRE');
        if ($genre !== null && $genre !== '') {
            $metadata->setGenre([$genre]);
        }

        $year = $this->first($tags, 'YEAR');
        if ($year !== null) {
            $parsed = (int) substr($year, 0, 4);
            if ($parsed > 0) {
                $metadata->setYear($parsed);
            }
        }

        $track = $this->first($tags, 'TRACKNUMBER');
        if ($track !== null) {
            $parsed = (int) preg_replace('/[^0-9].*/', '', $track);
            if ($parsed > 0) {
                $metadata->setTrackNumber($parsed);
            }
        }

        $disc = $this->first($tags, 'DISCNUMBER');
        if ($disc !== null) {
            $parsed = (int) preg_replace('/[^0-9].*/', '', $disc);
            if ($parsed > 0) {
                $metadata->setDiscNumber($parsed);
            }
        }

        $bpm = $this->first($tags, 'BPM');
        if ($bpm !== null) {
            $parsed = (int) $bpm;
            if ($parsed > 0) {
                $metadata->setBpm($parsed);
            }
        }

        // TLEN is in milliseconds
        $length = $this->first($tags, 'LENGTH');
        if ($length !== null) {
            $parsed = (float) $length;
            if ($parsed > 0) {
                $metadata->setDuration($parsed / 1000.0);
            }
        }

        // Pictures
        $pictures = [];
        foreach ($parser->getPictures() as $pictureData) {
            $pictures[] = new CoverArt(
                type: $pictureData['type'],
                mimeType: $pictureData['mimeType'],
                description: $pictureData['description'],
                imageData: $pictureData['imageData'],
            );
        }
        $metadata->setPictures($pictures);

        return $metadata;
    }

    /**
     * @param array<string, list<string>> $tags
     */
    private function first(array $tags, string $field): ?string
    {
        $values = $tags[strtoupper($field)] ?? [];

        return $values[0] ?? null;
    }
}
