<?php

declare(strict_types=1);

return [
    'name' => 'Music library',
    'summary' => 'Browse your collection by artist, album, song and genre, and find anything in it by typing.',
    'description' => "Bånder's music library: browse by artist, album, song and genre, return to what you played recently, and search from any page with Ctrl+K.",
    'headline' => 'Your collection,<br>ready to browse.',
    'lead' => [
        'A folder of music is easy to fill and hard to find anything in. Bånder reads the tags in your files and sets the collection out by artist, album, song and genre. Browse it, or press <kbd>Ctrl</kbd>+<kbd>K</kbd> and type what you want to hear.',
    ],
    'hero' => [
        'picture' => 'library',
        'alt' => "Bånder's home page: a row of recently played songs, a row of albums and the player along the bottom.",
        'caption' => 'Home · Captured in Bånder with sample music',
    ],
    'tour' => [
        [
            'id' => 'albums',
            'heading' => 'Start from the covers.',
            'text' => [
                "<strong>Albums</strong> lays the collection out as a grid of covers. Sort it by name, by artist or by year, or pick a genre above the grid to see only that genre's albums.",
                'Covers come from the artwork embedded in your files, so a tagged collection brings its own pictures.',
            ],
            'figure' => [
                'picture' => 'library-albums',
                'alt' => 'The Albums page: a grid of album covers with a sort menu and a row of genre filters above it.',
                'caption' => 'Albums · Captured in Bånder with sample music',
            ],
            'layout' => 'wide',
        ],
        [
            'id' => 'album',
            'heading' => 'Open an album.',
            'text' => [
                'Click a cover and the album opens in the panel beside the grid, with its tracks and how long they run. <strong>Play</strong> starts the album, and a double-click on a track starts it there.',
            ],
            'figure' => [
                'picture' => 'library-album',
                'alt' => 'The details panel for one album: its cover, a Play button, the number of tracks, the total time and the track list.',
                'caption' => 'Album details · Captured in Bånder with sample music',
            ],
        ],
        [
            'id' => 'songs',
            'heading' => 'Narrow it down.',
            'text' => [
                '<strong>Songs</strong> puts three columns above the song list: genre, artist and album. Pick a genre and the artists narrow to that genre. Pick an artist and you see their albums, and an album leaves only its own songs in the list below.',
                'Double-click a song to play it. The songs listed with it become the <a href="/features/player/">queue</a>.',
            ],
            'figure' => [
                'picture' => 'library-songs',
                'alt' => 'The Songs page: genre, artist and album columns above a list of songs, with one genre selected.',
                'caption' => 'Songs · Captured in Bånder with sample music',
            ],
            'layout' => 'wide',
        ],
        [
            'id' => 'search',
            'heading' => 'Or type its name.',
            'text' => [
                'Press <kbd>Ctrl</kbd>+<kbd>K</kbd>, or <kbd>Cmd</kbd>+<kbd>K</kbd> on a Mac, on any page and a search box opens over it. Type a few letters and the matching artists, albums and songs are listed as you type.',
            ],
            'figure' => [
                'picture' => 'library-search',
                'alt' => 'The search box with a few letters typed, listing matching artists, albums and songs under their headings.',
                'caption' => 'Search · Captured in Bånder with sample music',
            ],
        ],
    ],
    'guide' => [
        'needs' => 'A Bånder account with access to a music library that has been scanned. Bånder is pre-release and installs by building from source; the <a href="/docs/operator-guide/getting-started/installation/">installation guide</a> has the steps.',
        'steps' => [
            ['title' => 'Open Albums', 'text' => 'Choose <strong>Albums</strong> in the sidebar.'],
            ['title' => 'Look inside an album', 'text' => 'Click a cover to see its tracks in the panel on the right.'],
            ['title' => 'Play it', 'text' => 'Choose <strong>Play</strong> for the whole album, or double-click one track.'],
            ['title' => 'Search instead', 'text' => 'Press <kbd>Ctrl</kbd>+<kbd>K</kbd> (<kbd>Cmd</kbd>+<kbd>K</kbd> on a Mac), type part of a name and pick a result.'],
        ],
    ],
    'limits' => [
        'You see the libraries an administrator has given your account access to.',
        'New files show up after a scan, which an administrator starts. Bånder does not import files by watching a folder.',
        'A scan picks up MP3, FLAC, OGG, Opus, WAV, AAC and WMA files by their extension. That does not guarantee your browser can play every one of them, and audio transcoding is off by default.',
        'Only administrators change the catalog: albums, songs, genres and covers.',
    ],
    'manual' => [
        ['label' => 'Operator guide: library management', 'href' => '/docs/operator-guide/media/library-management/'],
    ],
];
