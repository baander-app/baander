<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Reader;

use App\Metadata\Domain\Model\CoverArt;
use App\Metadata\Domain\Model\ExtractedMetadata;
use Psr\Log\LoggerInterface;

/**
 * Reads OGG Vorbis comments by parsing the binary file directly.
 *
 * Uses OggParser for native Vorbis comment and METADATA_BLOCK_PICTURE
 * extraction. No external commands required.
 */
final class OggReader
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function read(string $path): ExtractedMetadata
    {
        if (!is_file($path) || !is_readable($path)) {
            $this->logger->warning('OGG file does not exist or is not readable', ['path' => $path]);

            return new ExtractedMetadata();
        }

        try {
            $parser = new OggParser($path, $this->logger);
            $parser->parse();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to parse OGG file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return new ExtractedMetadata();
        }

        return $this->mapToMetadata($parser);
    }

    private function mapToMetadata(OggParser $parser): ExtractedMetadata
    {
        $metadata = new ExtractedMetadata();

        $commentBlock = $parser->getVorbisCommentBlock();

        if ($commentBlock !== null) {
            $comments = $commentBlock['comments'];

            $metadata->setTitle($this->first($comments, 'TITLE'));
            $metadata->setAlbum($this->first($comments, 'ALBUM'));
            $metadata->setArtist($this->first($comments, 'ARTIST'));
            $metadata->setAlbumArtist($this->first($comments, 'ALBUMARTIST'));
            $metadata->setComposer($this->first($comments, 'COMPOSER'));
            $metadata->setComment($this->first($comments, 'DESCRIPTION') ?? $this->first($comments, 'COMMENT'));

            $genre = $this->first($comments, 'GENRE');
            if ($genre !== null) {
                $metadata->setGenre(array_map('trim', explode(',', $genre)));
            }

            $track = $this->first($comments, 'TRACKNUMBER');
            if ($track !== null) {
                $parsed = (int) preg_replace('/[^0-9].*/', '', $track);
                if ($parsed > 0) {
                    $metadata->setTrackNumber($parsed);
                }
            }

            $disc = $this->first($comments, 'DISCNUMBER');
            if ($disc !== null) {
                $parsed = (int) preg_replace('/[^0-9].*/', '', $disc);
                if ($parsed > 0) {
                    $metadata->setDiscNumber($parsed);
                }
            }

            $date = $this->first($comments, 'DATE') ?? $this->first($comments, 'YEAR');
            if ($date !== null) {
                $parsed = (int) substr($date, 0, 4);
                if ($parsed > 0) {
                    $metadata->setYear($parsed);
                }
            }

            $bpm = $this->first($comments, 'BPM');
            if ($bpm !== null) {
                $parsed = (int) $bpm;
                if ($parsed > 0) {
                    $metadata->setBpm($parsed);
                }
            }

            $duration = $this->first($comments, 'DURATION');
            if ($duration !== null) {
                $parsed = (float) $duration;
                if ($parsed > 0) {
                    $metadata->setDuration($parsed);
                }
            }

            $metadata->setMbid($this->first($comments, 'MUSICBRAINZ_TRACKID'));
            $metadata->setMbAlbumId($this->first($comments, 'MUSICBRAINZ_ALBUMID'));
            $metadata->setMbArtistId($this->first($comments, 'MUSICBRAINZ_ARTISTID'));
        }

        // Pictures
        $pictures = [];
        foreach ($parser->getPictures() as $pictureData) {
            if ($pictureData !== []) {
                $pictures[] = CoverArt::fromArray($pictureData);
            }
        }
        $metadata->setPictures($pictures);

        return $metadata;
    }

    /**
     * @param array<string, list<string>> $comments
     */
    private function first(array $comments, string $field): ?string
    {
        $values = $comments[strtoupper($field)] ?? [];

        return $values[0] ?? null;
    }
}
