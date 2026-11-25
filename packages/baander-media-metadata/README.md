# Media metadata

`baander/media-metadata` reads embedded audio tags and cover images from local
files. It is an internal Baander package in the `Baander\MediaMetadata` namespace.
[The reader](src/MetadataContentReader.php) detects a file's format and dispatches
to the appropriate container reader. Its result is
[`ExtractedMetadata`](src/Model/ExtractedMetadata.php), with embedded images represented
by [`CoverArt`](src/Model/CoverArt.php).

| Container | Audio extensions | Embedded metadata |
| --- | --- | --- |
| MPEG audio | `.mp3` | ID3v1/v2 |
| MP4 audio (AAC or ALAC) | `.m4a`, `.mp4a`, `.m4b` | iTunes atoms, MusicBrainz freeform fields and cover art |
| ASF/WMA | `.wma` | Content descriptions, extended attributes and pictures |
| RIFF/WAVE | `.wav`, `.wave` | INFO and embedded ID3, including pictures |
| AIFF/AIFC | `.aif`, `.aiff`, `.aifc` | Container text and embedded ID3, including pictures |
| ADTS AAC | `.aac` | Leading ID3 tags, including pictures |
| FLAC | `.flac` | Vorbis comments and pictures |
| Ogg Vorbis/Opus | `.ogg`, `.oga`, `.opus` | Vorbis/Opus comments and pictures |

The [format detector](src/FormatDetector.php) checks signatures before extension
fallback and distinguishes ADTS AAC from MPEG audio, including after a leading ID3
tag. It also reads MP4 containers named `.mp4`; the application's existing video
classification for that extension remains unchanged.
The [dispatcher](src/MetadataContentReader.php) accepts the aliases above with
`readAs()`. WAV and AIFF use populated ID3 fields before container text fields.

MP4, ASF, WAV and AIFF readers seek past audio payloads and bound metadata reads.
The FLAC parser reads only the blocks it decodes, at most 32 MiB of them per file,
seeks past the rest, stops after 1,024 blocks and keeps at most 65,536 comments or
seek points per block.
AAC exposes the transport's sample rate and channel configuration when available;
duration and bitrate remain unset. The catalog's existing audio probe supplies
authoritative stream properties. Reading tags never rewrites the source file.

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

The package has no separate executable or build step.
