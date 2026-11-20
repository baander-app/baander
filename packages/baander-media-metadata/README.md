# Media metadata

`baander/media-metadata` reads embedded audio tags and cover images from local
files. It is an internal Baander package in the `Baander\MediaMetadata` namespace.
[The reader](src/MetadataContentReader.php) detects a file's format and dispatches
to the ID3, FLAC, Ogg/Opus or WAV reader. Its result is
[`ExtractedMetadata`](src/Model/ExtractedMetadata.php), with embedded images represented
by [`CoverArt`](src/Model/CoverArt.php).

Call `MetadataContentReader::readMetadata($path)` to detect and read a file, or
`readAs($path, $format)` when its format is already known. Unsupported formats
return `null`; individual readers retain their existing empty-result and logging
behavior for unreadable or malformed files. The package requires PHP 8.5,
`ext-mbstring` and PSR logging, as declared in [composer.json](composer.json).

The application registers the readers in [services_packages.yaml](../../config/services_packages.yaml).
Its [Catalog port adapter](../../src/Metadata/Infrastructure/Reader/MetadataContentReaderAdapter.php)
delegates to the package. Matching against providers, Catalog updates and cover
storage remain in the application.

The source separates [metadata values](src/Model/), [format readers](src/Reader/)
and [binary parsers](src/Parser/). Format detection and reader selection stay at
`src/` root. [Package tests](tests/) follow these responsibilities, including
neutral tagged-file fixtures. Application matching, Catalog integration and
adapter tests stay in the application's test tree.

The root [Unit suite](../../phpunit.xml.dist) discovers the package's tests.
Use the existing [backend runners](../../docs-book/part-2-developer-guide/testing/README.md)
from the repository root.

Production embedding belongs to the application's
[TypePHP configuration](../../tools/typephp/application.yml). The package has no
separate executable or build step.
