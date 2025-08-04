# MediaMeta

MediaMeta provides a unified interface for reading ID3 tags from audio files. It supports both ID3v1 and ID3v2 formats
and can extract image data from APIC tags.

## Features

- Read ID3v1 and ID3v2 tags from audio files
- Extract common metadata (title, artist, album, genre, year, comments)
- Extract image data from APIC tags
- Support for different image types (front cover, back cover, artist, etc.)
- Support for different character encodings (UTF-8, UTF-16, UTF-16LE, ISO-8859-1)
- Support for multiple artists and comments
- Direct access to frame objects for advanced usage

## Usage

### Basic Usage

```php
// Create a MediaMeta instance
$mediaMeta = new MediaMeta('/path/to/audio/file.mp3');

// Get basic metadata
$title = $mediaMeta->getTitle();
$artist = $mediaMeta->getArtist();
$album = $mediaMeta->getAlbum();
$genre = $mediaMeta->getGenre();
$year = $mediaMeta->getYear();
$comment = $mediaMeta->getComment();

// Get multiple artists
$artists = $mediaMeta->getArtists();
foreach ($artists as $artist) {
    echo "Artist: $artist\n";
}

// Get multiple comments
$comments = $mediaMeta->getComments();
foreach ($comments as $comment) {
    echo "Comment: " . $comment->getText() . "\n";
    echo "Language: " . $comment->getLanguage() . "\n";
    echo "Description: " . $comment->getDescription() . "\n";
}

// Get the front cover image
$frontCover = $mediaMeta->getFrontCoverImage();
if ($frontCover) {
    $imageData = $frontCover->getImageData();
    $mimeType = $frontCover->getMimeType();
    $description = $frontCover->getDescription();

    // Save the image to a file
    file_put_contents('cover.jpg', $imageData);
}
```

### Working with Different Image Types

```php
// Get all images
$images = $mediaMeta->getImages();

// Get images of a specific type
$frontCovers = $mediaMeta->getImagesByType(MediaMeta::IMAGE_COVER_FRONT);
$backCovers = $mediaMeta->getImagesByType(MediaMeta::IMAGE_COVER_BACK);
$artistImages = $mediaMeta->getImagesByType(MediaMeta::IMAGE_ARTIST);

// Get the first image of a specific type
$frontCover = $mediaMeta->getImageByType(MediaMeta::IMAGE_COVER_FRONT);
$backCover = $mediaMeta->getImageByType(MediaMeta::IMAGE_COVER_BACK);
$artistImage = $mediaMeta->getImageByType(MediaMeta::IMAGE_ARTIST);

// Convenience methods for common image types
$frontCover = $mediaMeta->getFrontCoverImage();
$backCover = $mediaMeta->getBackCoverImage();
$artistImage = $mediaMeta->getArtistImage();

// Get image data directly
$frontCoverData = $mediaMeta->getFrontCoverImageData();
$imageDataByType = $mediaMeta->getImageDataByType(MediaMeta::IMAGE_COVER_FRONT);
```

### Working with Different Character Encodings

```php
// Create a MediaMeta instance with a specific encoding
$mediaMeta = new MediaMeta('/path/to/audio/file.mp3', Encoding::UTF8);

// Change the encoding
$mediaMeta->setEncoding(Encoding::UTF16);
$mediaMeta->setEncoding(Encoding::UTF16LE);
$mediaMeta->setEncoding(Encoding::ISO88591);

// Get the current encoding
$encoding = $mediaMeta->getEncoding();
```

### Working with Frame Objects Directly

```php
// Get frame objects directly
$titleFrame = $mediaMeta->getTitleFrame();
$artistFrame = $mediaMeta->getArtistFrame();
$albumFrame = $mediaMeta->getAlbumFrame();
$genreFrame = $mediaMeta->getGenreFrame();
$yearFrame = $mediaMeta->getYearFrame();
$commentFrame = $mediaMeta->getCommentFrame();

// Work with frame objects
if ($titleFrame) {
    echo "Title: " . $titleFrame->getTitle() . "\n";
    $titleFrame->setTitle("New Title");
}

if ($artistFrame) {
    echo "Artist: " . $artistFrame->getArtist() . "\n";
    $artistFrame->setArtist("New Artist");

    // Work with multiple artists
    $artists = $artistFrame->getArtists();
    $artistFrame->setArtists(["Artist 1", "Artist 2"]);
}

if ($commentFrame) {
    echo "Comment: " . $commentFrame->getText() . "\n";
    echo "Language: " . $commentFrame->getLanguage() . "\n";
    echo "Description: " . $commentFrame->getDescription() . "\n";

    $commentFrame->setText("New Comment");
    $commentFrame->setLanguage("eng");
    $commentFrame->setDescription("New Description");
}

// Get a specific comment by description
$commentFrame = $mediaMeta->getCommentFrameByDescription("Lyrics");
if ($commentFrame) {
    echo "Lyrics: " . $commentFrame->getText() . "\n";
}
```

## Testing

A test script is provided to verify that the `MediaMeta` class works correctly with various audio files:

```bash
php app/Modules/MediaMeta/MediaMetaTest.php /path/to/audio/file.mp3
```

The test script will display basic metadata, list all images found in the file, and test different character encodings.

## Image Types

The following image types are supported:

- `MediaMeta::IMAGE_OTHER` (0): Other
- `MediaMeta::IMAGE_FILE_ICON` (1): 32x32 pixels file icon (PNG only)
- `MediaMeta::IMAGE_OTHER_FILE_ICON` (2): Other file icon
- `MediaMeta::IMAGE_COVER_FRONT` (3): Cover (front)
- `MediaMeta::IMAGE_COVER_BACK` (4): Cover (back)
- `MediaMeta::IMAGE_LEAFLET` (5): Leaflet page
- `MediaMeta::IMAGE_MEDIA` (6): Media (e.g. label side of CD)
- `MediaMeta::IMAGE_LEAD_ARTIST` (7): Lead artist/lead performer/soloist
- `MediaMeta::IMAGE_ARTIST` (8): Artist/performer
- `MediaMeta::IMAGE_CONDUCTOR` (9): Conductor
- `MediaMeta::IMAGE_BAND` (10): Band/Orchestra
- `MediaMeta::IMAGE_COMPOSER` (11): Composer
- `MediaMeta::IMAGE_LYRICIST` (12): Lyricist/text writer
- `MediaMeta::IMAGE_RECORDING_LOCATION` (13): Recording Location
- `MediaMeta::IMAGE_DURING_RECORDING` (14): During recording
- `MediaMeta::IMAGE_DURING_PERFORMANCE` (15): During performance
- `MediaMeta::IMAGE_SCREEN_CAPTURE` (16): Movie/video screen capture
- `MediaMeta::IMAGE_FISH` (17): A bright coloured fish
- `MediaMeta::IMAGE_ILLUSTRATION` (18): Illustration
- `MediaMeta::IMAGE_BAND_LOGO` (19): Band/artist logotype
- `MediaMeta::IMAGE_PUBLISHER_LOGO` (20): Publisher/Studio logotype
