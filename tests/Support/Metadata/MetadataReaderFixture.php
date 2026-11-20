<?php

declare(strict_types=1);

namespace App\Tests\Support\Metadata;

use App\Metadata\Infrastructure\Matching\MatchingStrategy;
use App\Metadata\Infrastructure\Matching\Validator\AlbumValidator;
use App\Metadata\Infrastructure\Matching\Validator\ArtistValidator;
use App\Metadata\Infrastructure\Matching\Validator\SongValidator;
use App\Metadata\Infrastructure\Reader\MediaFileMetadata;
use App\Metadata\Infrastructure\Reader\MetadataContentReaderAdapter;
use Baander\MediaMetadata\FormatDetector;
use Baander\MediaMetadata\MetadataContentReader as PackageMetadataContentReader;
use Baander\MediaMetadata\Reader\FlacReader;
use Baander\MediaMetadata\Reader\Id3Reader;
use Baander\MediaMetadata\Reader\OggReader;
use Baander\MediaMetadata\Reader\WavReader;
use Psr\Log\NullLogger;

final class MetadataReaderFixture
{
    /** The tag reading the extract and match routes and commands share, with the library scan's readers. */
    public static function reader(): MediaFileMetadata
    {
        $logger = new NullLogger();
        $formats = new FormatDetector();

        return new MediaFileMetadata(
            $formats,
            new MetadataContentReaderAdapter(new PackageMetadataContentReader($formats, new Id3Reader($logger), new FlacReader($logger), new OggReader($logger), new WavReader($logger))),
            new MatchingStrategy(new ArtistValidator(), new AlbumValidator(), new SongValidator()),
        );
    }
}
