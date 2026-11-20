# Media metadata

`baander/media-metadata` reads embedded audio tags and cover images from local
files. It is an internal Baander package in the `Baander\MediaMetadata` namespace.
[The reader](src/MetadataContentReader.php) detects a file's format and dispatches
to the ID3, FLAC, Ogg/Opus or WAV reader. Its result is
[`ExtractedMetadata`](src/ExtractedMetadata.php), with embedded images represented
by [`CoverArt`](src/CoverArt.php).

Call `MetadataContentReader::readMetadata($path)` to detect and read a file, or
`readAs($path, $format)` when its format is already known. Unsupported formats
return `null`; individual readers retain their existing empty-result and logging
behavior for unreadable or malformed files. The package requires PHP 8.5,
`ext-mbstring` and PSR logging, as declared in [composer.json](composer.json).

The application registers the readers in [services.yaml](../../config/services.yaml).
Its [Catalog port adapter](../../src/Metadata/Infrastructure/Reader/MetadataContentReader.php)
delegates to the package. Matching against providers, Catalog updates and cover
storage remain in the application.

Production embedding belongs to the application's
[TypePHP configuration](../../tools/typephp/application.yml). The package has no
separate executable or build step.
