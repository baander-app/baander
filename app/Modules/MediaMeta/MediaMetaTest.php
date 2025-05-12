<?php

namespace App\Modules\MediaMeta;

/**
 * Simple test script for the MediaMeta class.
 * 
 * Usage: php MediaMetaTest.php <path_to_audio_file>
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

// Check if a file path was provided
if ($argc < 2) {
    echo "Usage: php MediaMetaTest.php <path_to_audio_file>\n";
    exit(1);
}

$filePath = $argv[1];

// Check if the file exists
if (!file_exists($filePath)) {
    echo "Error: File not found: $filePath\n";
    exit(1);
}

try {
    // Create a MediaMeta instance
    $mediaMeta = new MediaMeta($filePath);

    // Display basic metadata
    echo "File: $filePath\n";
    echo "Title: " . ($mediaMeta->getTitle() ?? 'N/A') . "\n";
    echo "Artist: " . ($mediaMeta->getArtist() ?? 'N/A') . "\n";
    echo "Album: " . ($mediaMeta->getAlbum() ?? 'N/A') . "\n";
    echo "Genre: " . ($mediaMeta->getGenre() ?? 'N/A') . "\n";
    echo "Year: " . ($mediaMeta->getYear() ?? 'N/A') . "\n";
    echo "Comment: " . ($mediaMeta->getComment() ?? 'N/A') . "\n";

    // Display artists as array
    $artists = $mediaMeta->getArtists();
    if (count($artists) > 0) {
        echo "\nArtists:\n";
        foreach ($artists as $index => $artist) {
            echo "[$index] $artist\n";
        }
    }

    // Display comments
    $comments = $mediaMeta->getComments();
    if (count($comments) > 0) {
        echo "\nComments:\n";
        foreach ($comments as $index => $comment) {
            echo "[$index] Language: " . $comment->getLanguage() . "\n";
            echo "     Description: " . $comment->getDescription() . "\n";
            echo "     Text: " . $comment->getText() . "\n";
        }
    }

    // Display image information
    $images = $mediaMeta->getImages();
    echo "\nImages found: " . count($images) . "\n";

    if (count($images) > 0) {
        echo "\nImage Types:\n";
        foreach ($images as $index => $image) {
            $type = $image->getImageType();
            $typeName = isset(Apic::$types[$type]) 
                ? Apic::$types[$type] 
                : "Unknown ($type)";

            echo "[$index] Type: $typeName\n";
            echo "     MIME: " . $image->getMimeType() . "\n";
            echo "     Size: " . strlen($image->getImageData()) . " bytes\n";
            echo "     Description: " . $image->getDescription() . "\n";
        }

        // Check for front cover specifically
        $frontCover = $mediaMeta->getFrontCoverImage();
        if ($frontCover) {
            echo "\nFront Cover Found:\n";
            echo "MIME: " . $frontCover->getMimeType() . "\n";
            echo "Size: " . strlen($frontCover->getImageData()) . " bytes\n";

            // Save the front cover to a file
            $extension = $frontCover->getMimeType() === 'image/jpeg' ? 'jpg' : 
                        ($frontCover->getMimeType() === 'image/png' ? 'png' : 'img');
            $outputFile = __DIR__ . '/front_cover.' . $extension;
            file_put_contents($outputFile, $frontCover->getImageData());
            echo "Front cover saved to: $outputFile\n";
        } else {
            echo "\nNo front cover found.\n";
        }
    }

    // Test different encodings
    echo "\nTesting different encodings:\n";

    // UTF-8
    $mediaMeta->setEncoding(Encoding::UTF8);
    echo "UTF-8 Title: " . ($mediaMeta->getTitle() ?? 'N/A') . "\n";

    // UTF-16
    $mediaMeta->setEncoding(Encoding::UTF16);
    echo "UTF-16 Title: " . ($mediaMeta->getTitle() ?? 'N/A') . "\n";

    // UTF-16LE
    $mediaMeta->setEncoding(Encoding::UTF16LE);
    echo "UTF-16LE Title: " . ($mediaMeta->getTitle() ?? 'N/A') . "\n";

    // ISO-8859-1
    $mediaMeta->setEncoding(Encoding::ISO88591);
    echo "ISO-8859-1 Title: " . ($mediaMeta->getTitle() ?? 'N/A') . "\n";

    echo "\nTest completed successfully.\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
