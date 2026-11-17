<?php

declare(strict_types=1);

return [
    'description' => "A tour of Bånder's features for listeners, operators and developers, with pictures, examples, short guides and each feature's limits.",
    'opening' => [
        'headline' => 'Take the tour.',
        'lead' => [
            'Each page shows one part of Bånder: what it does, what it looks like and how to try it. Start wherever you like.',
        ],
    ],
    'groups' => [
        [
            'id' => 'listeners',
            'title' => 'For listening',
            'lead' => 'Browse your collection, play it and shape how it sounds.',
            'pages' => ['library', 'player', 'equalizer', 'playlists', 'destinations'],
        ],
        [
            'id' => 'operators',
            'title' => 'For running it',
            'lead' => 'Add libraries, follow the work and manage an instance.',
            'pages' => ['libraries', 'administration', 'console'],
        ],
        [
            'id' => 'developers',
            'title' => 'For building on it',
            'lead' => 'See how the code is organized and try a change.',
            'pages' => ['developers'],
        ],
    ],
    'more' => [
        [
            'name' => 'Movies',
            'text' => 'Movies have their own catalog, with browse and detail pages and a built-in video player. Which formats play depends on the encoders of your worker deployment and on your browser.',
            'manual' => ['label' => 'Operator guide: transcoding', 'href' => '/docs/operator-guide/media/transcoding/'],
        ],
        [
            'name' => 'Internet radio',
            'text' => 'Browse radio stations by country and star the ones you return to. Stations appear only after an operator has synced them.',
            'manual' => ['label' => 'Command reference: app:radio:sync', 'href' => '/docs/operator-guide/commands/app-radio-sync/'],
        ],
        [
            'name' => 'Lyrics',
            'text' => "When an administrator has fetched a song's lyrics from LRCLIB, a panel beside the queue shows them and follows synced lyrics line by line. Automatic fetching is off by default.",
            'manual' => ['label' => 'Operator guide: external APIs', 'href' => '/docs/operator-guide/media/external-apis/'],
        ],
        [
            'name' => 'Audio transcoding',
            'text' => 'For clients that need another format, the server converts tracks to Opus, AAC or MP3. It is off by default, and a super administrator switches it on where the deployment profile permits encoding.',
            'manual' => ['label' => 'Operator guide: audio transcoding', 'href' => '/docs/operator-guide/media/transcoding/#audio-transcoding'],
        ],
        [
            'name' => 'Metadata matching',
            'text' => "An administrator can match albums, songs and artists against MusicBrainz, and a match can add the release's front cover. Automatic matching is off by default.",
            'manual' => ['label' => 'Operator guide: MusicBrainz matching', 'href' => '/docs/operator-guide/media/external-apis/#musicbrainz-matching'],
        ],
        [
            'name' => 'Accounts and library access',
            'text' => 'Everyone gets an account of their own, and a super administrator decides which libraries it sees. Administrators see every library.',
            'manual' => ['label' => 'Operator guide: user management', 'href' => '/docs/operator-guide/administration/user-management/'],
        ],
        [
            'name' => 'Notifications',
            'text' => 'Notifications appear in the application, and an operator can add delivery by email and to webhooks.',
            'manual' => ['label' => 'Operator guide: notifications', 'href' => '/docs/operator-guide/administration/notifications/'],
        ],
    ],
];
