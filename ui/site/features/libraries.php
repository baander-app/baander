<?php

declare(strict_types=1);

return [
    'name' => 'Libraries and scanning',
    'summary' => 'Give a directory of music a name, scan it, and background workers read the tags and build the catalog.',
    'description' => "Bånder's libraries: name a directory and scan it, and workers read the tags and build the catalog. MusicBrainz and lyrics lookups are off by default.",
    'headline' => 'From a folder<br>to a catalog.',
    'lead' => [
        'You have copied an album onto the server and want to see it in the catalog. A library gives a directory a name, a scan finds the files in it, and background workers read their tags and add the albums and tracks. Registering a library imports nothing until you scan it.',
    ],
    'hero' => [
        'picture' => 'libraries',
        'alt' => "A library's page in the administration: its name, path and scan status above counts of songs, albums, artists and genres.",
        'caption' => 'A library · Captured in Bånder with sample music',
    ],
    'tour' => [
        [
            'id' => 'register',
            'heading' => 'Give a directory a name.',
            'text' => [
                'Choose <strong>Add Library</strong> and enter a name, the path of the directory and its type. The path is the one the server sees inside its containers, and the form checks it while you type.',
                'Registering copies nothing: the files stay where they are.',
            ],
            'figure' => [
                'picture' => 'libraries-add',
                'alt' => 'The Add Library form with fields for a name, a path, a type and an optional slug.',
                'caption' => 'Adding a library · Captured in Bånder',
            ],
        ],
        [
            'id' => 'scan',
            'heading' => 'A scan finds the files.<br>Workers build the catalog.',
            'text' => [
                'Start a scan and its first stage, discovery, walks the directory, picks out the files it recognizes by extension and queues each directory that changed. Workers then read the tags in the background and create the albums and tracks.',
                "So a scan marked <strong>Completed</strong> has only finished finding files, and the catalog may still be filling. The counts on the library's own page show what has arrived.",
            ],
            'figure' => [
                'picture' => 'libraries-scan',
                'alt' => 'A library in the list of libraries, marked Completed, with the time of its last scan and buttons to scan it, open its details, edit it or delete it.',
                'caption' => 'After a scan · Captured in Bånder with sample music',
            ],
            'layout' => 'wide',
        ],
        [
            'id' => 'covers',
            'heading' => 'Covers come from the files.',
            'text' => [
                "Tagged music often carries its cover art inside the file. When a scan brings in an album that has no cover, a worker reads the image embedded in its audio files and stores it as the album's cover.",
            ],
            'figure' => [
                'picture' => 'library-albums',
                'alt' => 'The albums page as a grid of covers, each with a title and an artist beneath it.',
                'caption' => 'Albums · Captured in Bånder with sample music',
            ],
        ],
        [
            'id' => 'enrichment',
            'heading' => 'More detail, if you switch it on.',
            'text' => [
                'A scan reads your local files and their tags. You can import and play a collection without configuring any outside service.',
                'Two server settings, both off by default, go further: automatic MusicBrainz matching for the albums a scan adds, and automatic lyrics fetching from LRCLIB for new tracks. A super administrator switches them on under <strong>Settings</strong>.',
            ],
            'figure' => [
                'picture' => 'libraries-enrichment',
                'alt' => 'The Content group of the server settings: switches for lyrics fetching, MusicBrainz matching and recommendations, all off.',
                'caption' => 'Settings, off by default · Captured in Bånder',
            ],
            'layout' => 'wide',
        ],
    ],
    'guide' => [
        'needs' => 'A Bånder instance with its worker deployment running, an administrator account, and a directory of music that the app and worker containers can read at the same path. Bånder is pre-release and installs by building from source; the <a href="/docs/operator-guide/getting-started/installation/">installation guide</a> has the steps.',
        'steps' => [
            ['title' => 'Open the library administration', 'text' => 'Open <strong>Settings</strong>, choose <strong>Admin</strong> under Administration, then <strong>Library</strong> in the admin sidebar.'],
            ['title' => 'Add the library', 'text' => 'Choose <strong>Add Library</strong>. Enter a <strong>Name</strong> and the <strong>Path</strong> of the directory inside the container, leave <strong>Type</strong> on Music and choose <strong>Create</strong>.'],
            ['title' => 'Scan it', 'text' => "On the library's card choose <strong>Scan</strong>, then <strong>Start Scan</strong>."],
            ['title' => 'Watch the catalog fill', 'text' => 'Choose <strong>Detail</strong>. <strong>Statistics</strong> counts the songs, albums and artists that have reached the catalog.'],
        ],
    ],
    'limits' => [
        'Registering a library imports nothing. Files reach the catalog after a scan, once the background workers have ingested what it found, so the worker deployment must be running.',
        "A scan's totals and its Completed status describe discovery. They do not show that every queued directory has reached the catalog.",
        'File watching imports nothing. <code>app:watch-files</code> prints filesystem events; run a scan when files change.',
        'The types Podcast, Audiobook and TV show can be registered, but scanning them is not implemented.',
        'A scan picks files by extension. Finding a file does not guarantee that a browser can play the codec inside it, and tags in M4A files are not read.',
        'A scan does not promise to remove catalog entries for deleted files: a missing file stays in the catalog, marked unavailable.',
        'With automatic matching on, Bånder sends requests about your music to MusicBrainz and the Cover Art Archive. With lyrics fetching on, it sends them to LRCLIB.',
    ],
    'manual' => [
        ['label' => 'Operator guide: library management', 'href' => '/docs/operator-guide/media/library-management/'],
        ['label' => 'Operator guide: external APIs', 'href' => '/docs/operator-guide/media/external-apis/'],
        ['label' => 'Command reference: app:library:scan', 'href' => '/docs/operator-guide/commands/app-library-scan/'],
    ],
];
