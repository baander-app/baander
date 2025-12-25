# Metadata Delimiter Service

## Overview

The `MetadataDelimiterService` provides smart detection and splitting of multi-value fields in music metadata (artists, genres, etc.).

## Features

- **Smart Detection**: Automatically detects the most likely delimiter based on patterns
- **Priority Order**: Tries delimiters in order of likelihood (semicolon first)
- **Known Exceptions**: Won't split known artist names with delimiters (e.g., "AC/DC")
- **Configurable**: Fully customizable via constructor options
- **Fallback**: Gracefully falls back through multiple delimiters

## Common Music Delimiters

Based on research from [MusicBrainz](https://community.metabrainz.org/t/multiple-artists-and-genres-as-multiple-tags/412255), [Foobar](https://www.hydrogenaudio.org/), and [MP3Tag](https://community.mp3tag.de/t/should-i-use-or-or-as-a-separator/45708):

| Delimiter | Usage | Notes |
|-----------|-------|-------|
| `;` | **Standard** - MusicBrainz, Foobar, Jellyfin | Most recommended, rarely in artist names |
| `/` | Common | Conflicts with "AC/DC" |
| `\` | Rare | Sometimes used |
| `,` | ID3v2 standard | Conflicts with "Earth, Wind & Fire" |
| `&` | Informal | "Artist & Another" |
| ` vs ` | Versus | "Artist vs Artist" (battles) |
| ` feat.` | Featured | "Artist feat. FeaturedArtist" |

## Usage

### Basic Usage (Default Options)

```php
use App\Services\Metadata\MetadataDelimiterService;

$service = new MetadataDelimiterService();

// Automatically detect and split artists
$artists = $service->splitArtists('Artist1; Artist2; Artist3');
// ['Artist1', 'Artist2', 'Artist3']

$artists = $service->splitArtists('Artist1/Artist2');
// ['Artist1', 'Artist2']

// Won't split known exceptions
$artists = $service->splitArtists('AC/DC');
// ['AC/DC']

// If already an array from metadata, returns as-is
$artists = $service->splitArtists(['Artist1', 'Artist2']);
// ['Artist1', 'Artist2']

// Split genres
$genres = $service->splitGenres('Rock; Pop; Jazz');
// ['Rock', 'Pop', 'Jazz']
```

### Custom Options

```php
$options = [
    'smart_detection' => false,
    'artist_delimiters' => [';', '/', ','],
    'genre_delimiters' => [';', '/'],
];

$service = new MetadataDelimiterService($options);

// Will only try configured delimiters
$artists = $service->splitArtists('Artist1, Artist2, Artist3');
```

### In ScanDirectoryJob

```php
use App\Jobs\Library\Music\ScanDirectoryJob;

// Default options
ScanDirectoryJob::dispatch($directory, $library);

// Custom options
ScanDirectoryJob::dispatch($directory, $library, [
    'smart_detection' => true,
    'artist_delimiters' => [';', '/', '&', 'feat.'],
    'genre_delimiters' => [';', '/', ','],
    'min_occurrences' => 2,  // Require 2+ occurrences to detect
    'fallback_all_delimiters' => true,
]);
```

## Configuration

### Known Artist Exceptions

Artists with delimiters in their names that shouldn't be split are configured in `config/scanner.php`:

```php
'delimiter_rules' => [
    'known_artist_exceptions' => [
        'AC/DC',
        'Earth, Wind & Fire',
        'R.E.M.',
        // ... more artists
    ],
]
```

You can customize this list to add or remove artists based on your library.

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `smart_detection` | bool | `true` | Enable smart pattern-based detection |
| `artist_delimiters` | array | `[';', '/', '&']` | Fixed delimiters to try (if smart_detection off) |
| `genre_delimiters` | array | `[';', '/']` | Delimiters for genre field |
| `delimiter_priority` | array | See code | Priority order for auto-detection |
| `min_occurrences` | int | `1` | Minimum occurrences required to detect delimiter |
| `fallback_all_delimiters` | bool | `true` | Try all known delimiters if configured ones fail |
| `known_artist_exceptions` | array | `config('scanner.music.delimiter_rules.known_artist_exceptions')` | Artists that shouldn't be split (configured in `config/scanner.php`) |

## Real-World Examples

```php
// freac output with semicolon delimiter
$service = new MetadataDelimiterService();
$artists = $service->splitArtists('Taylor Swift; Bon Iver');
// ['Taylor Swift', 'Bon Iver']

// Featuring artists
$service = new MetadataDelimiterService([
    'artist_delimiters' => [';', '/', '&', 'feat.'],
]);
$artists = $service->splitArtists('Drake feat. Future');
// ['Drake', 'Future']

// Band with slash in name (won't split incorrectly)
$artists = $service->splitArtists('AC/DC');
// ['AC/DC'] - single artist, not split

// Complex example
$service = new MetadataDelimiterService([
    'smart_detection' => true,
]);
$artists = $service->splitArtists('Earth, Wind & Fire; Tower of Power');
// ['Earth, Wind & Fire', 'Tower of Power']
```

## Sources

- [How to tag mp3 files with multiple artists properly - Reddit](https://www.reddit.com/r/musichoarder/comments/1c2oazk/how_to_tag_mp3_files_with_multiple_artists/)
- [Should I use ";" or "\" or "-" as a separator? - MP3Tag Community](https://community.mp3tag.de/t/should-i-use-or-or-as-a-separator/45708)
- [Multiple artists and genres as multiple tags - MusicBrainz](https://community.metabrainz.org/t/multiple-artists-and-genres-as-multiple-tags/412255)
- [Tagging Guidelines - Navidrome](https://www.navidrome.org/docs/usage/tagging-guidelines/)
- [Split artist names in music metadata - Emby Community](https://emby.media/community/index.php//topic/89711-split-artist-names-in-music-metadata/)
