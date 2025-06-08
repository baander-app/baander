<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Http\Integrations\Discogs\DiscogsClient;
use App\Http\Integrations\Discogs\Filters\ArtistFilter;
use App\Http\Integrations\Discogs\Filters\ReleaseFilter;
use App\Http\Integrations\Discogs\Filters\MasterFilter;
use App\Http\Integrations\Discogs\Filters\LabelFilter;
use App\Http\Integrations\Discogs\Models\Artist;
use App\Http\Integrations\Discogs\Models\Release;
use App\Http\Integrations\Discogs\Models\Master;
use App\Http\Integrations\Discogs\Models\Label;
use App\Services\GuzzleService;

// Create a new GuzzleService
$guzzleService = new GuzzleService();

// Create a new DiscogsClient
$discogsClient = new DiscogsClient($guzzleService);

// Example 1: Search for artists
echo "Searching for artists...\n";
$artistFilter = new ArtistFilter(
    q: 'Radiohead',
    page: 1,
    per_page: 5
);

$artists = $discogsClient->search->artist($artistFilter);
if ($artists) {
    echo "Found " . count($artists) . " artists\n";
    foreach ($artists as $artist) {
        // Artists are now Artist model instances
        echo "- {$artist->title} (ID: {$artist->id})\n";
    }

    // Get pagination info
    $pagination = $discogsClient->search->getPagination();
    if ($pagination) {
        echo "Page {$pagination['page']} of {$pagination['pages']} (Total items: {$pagination['items']})\n";
    }
} else {
    echo "No artists found\n";
}

echo "\n";

// Example 2: Look up an artist by ID
echo "Looking up artist by ID...\n";
$artistId = 3840; // Radiohead
$artist = $discogsClient->lookup->artist($artistId);
if ($artist) {
    // Artist is now an Artist model instance
    echo "Artist: {$artist->name}\n";
    echo "Profile: " . substr($artist->profile ?? '', 0, 100) . "...\n";
} else {
    echo "Artist not found\n";
}

echo "\n";

// Example 3: Get artist releases with pagination
echo "Getting artist releases...\n";
$releases = $discogsClient->lookup->artistReleases($artistId, 1, 3);
if ($releases && isset($releases['releases'])) {
    echo "Found " . count($releases['releases']) . " releases\n";
    foreach ($releases['releases'] as $release) {
        // Releases are now Release model instances
        echo "- {$release->title} ({$release->year})\n";
    }

    // Show pagination info
    if (isset($releases['pagination'])) {
        $pagination = $releases['pagination'];
        echo "Page {$pagination['page']} of {$pagination['pages']} (Total items: {$pagination['items']})\n";
    }
} else {
    echo "No releases found\n";
}

echo "\n";

// Example 4: Search for releases
echo "Searching for releases...\n";
$releaseFilter = new ReleaseFilter(
    artist: 'Radiohead',
    title: 'OK Computer',
    page: 1,
    per_page: 3
);

$releases = $discogsClient->search->release($releaseFilter);
if ($releases) {
    echo "Found " . count($releases) . " releases\n";
    foreach ($releases as $release) {
        // Releases are now Release model instances
        echo "- {$release->title} ({$release->year})\n";
    }
} else {
    echo "No releases found\n";
}

echo "\n";

// Example 5: Look up a release by ID
echo "Looking up release by ID...\n";
$releaseId = 1475088; // OK Computer
$release = $discogsClient->lookup->release($releaseId);
if ($release) {
    // Release is now a Release model instance
    echo "Release: {$release->title}\n";
    $artistName = isset($release->artists[0]['name']) ? $release->artists[0]['name'] : 'Unknown';
    echo "Artist: {$artistName}\n";
    echo "Year: {$release->year}\n";
    echo "Formats: ";
    if ($release->formats) {
        foreach ($release->formats as $format) {
            echo "{$format['name']} ";
        }
    }
    echo "\n";
} else {
    echo "Release not found\n";
}

echo "\nDiscogs API Client Example Complete\n";
