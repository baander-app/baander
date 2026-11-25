<?php

declare(strict_types=1);

namespace App\Tests\Support\Metadata;

use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Filesystem\LibraryMediaFileResolver;
use App\Metadata\Infrastructure\Matching\MatchingStrategy;
use App\Metadata\Infrastructure\Matching\Validator\AlbumValidator;
use App\Metadata\Infrastructure\Matching\Validator\ArtistValidator;
use App\Metadata\Infrastructure\Matching\Validator\SongValidator;
use App\Metadata\Infrastructure\Reader\MediaFileMetadata;
use App\Metadata\Infrastructure\Reader\MetadataContentReaderAdapter;
use App\Shared\Domain\ValueObject\FilesystemType;
use Baander\MediaMetadata\FormatDetector;
use Baander\MediaMetadata\MetadataContentReader as PackageMetadataContentReader;
use Baander\MediaMetadata\Reader\FlacReader;
use Baander\MediaMetadata\Reader\Id3Reader;
use Baander\MediaMetadata\Reader\OggReader;
use Baander\MediaMetadata\Reader\WavReader;
use Baander\MediaMetadata\Reader\Mp4Reader;
use Baander\MediaMetadata\Reader\AsfReader;
use Baander\MediaMetadata\Reader\AiffReader;
use Baander\MediaMetadata\Reader\AacReader;
use Psr\Log\NullLogger;

final class MetadataReaderFixture
{
    /**
     * The tag reading the extract and match routes and commands share, with the library scan's
     * readers, confined to the libraries the repository lists.
     */
    public static function reader(LibraryRepositoryInterface $libraries): MediaFileMetadata
    {
        return new MediaFileMetadata(
            new FormatDetector(),
            self::contentReader(),
            new MatchingStrategy(new ArtistValidator(), new AlbumValidator(), new SongValidator()),
            new LibraryMediaFileResolver($libraries),
        );
    }

    /** A local music library with this root, for a repository stub to list. */
    public static function library(string $root): Library
    {
        return Library::create(
            name: 'Tagged media',
            slug: new LibrarySlug('tagged-media'),
            path: new LibraryPath($root),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );
    }

    public static function contentReader(): MetadataContentReaderAdapter
    {
        $logger = new NullLogger();

        return new MetadataContentReaderAdapter(new PackageMetadataContentReader(
            new FormatDetector(), new Id3Reader($logger), new FlacReader($logger),
            new OggReader($logger), new WavReader($logger), new Mp4Reader($logger),
            new AsfReader($logger), new AiffReader($logger), new AacReader($logger),
        ));
    }
}
