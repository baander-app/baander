<?php

declare(strict_types=1);

return [
    'name' => 'Playlists',
    'summary' => 'Collect songs into playlists and share them with other people on your server.',
    'description' => "Bånder's playlists: choose the songs yourself, play a playlist from its own page, share it with other accounts on your server and let them add songs.",
    'headline' => 'Choose the songs.<br>Share the list.',
    'lead' => [
        'Some songs belong together, whatever albums they came from. Put them in a playlist, play it from its own page, and share it with other people on your server. Switch on collaboration and they can add songs to it too.',
    ],
    'hero' => [
        'picture' => 'playlists-window',
        'alt' => "A playlist's page: its name and description, the number of songs and their total time, Play all and Share buttons, and the songs in order.",
        'caption' => 'A playlist · Captured in Bånder with sample music',
    ],
    'tour' => [
        [
            'id' => 'add-songs',
            'heading' => 'Add songs as you find them.',
            'text' => [
                "On an <a href=\"/features/library/\">album's page</a>, right-click a song and point to <strong>Add to Playlist</strong>. Your playlists are listed there, so one more click adds the song.",
                '<strong>Choose Playlist…</strong> lets you search your playlists or name a new one.',
            ],
            'figure' => [
                'picture' => 'playlists-add',
                'alt' => 'The Add to Playlist dialog: a search field, the list of playlists and a field for naming a new one.',
                'caption' => 'Add to Playlist · Captured in Bånder with sample music',
            ],
        ],
        [
            'id' => 'share',
            'heading' => 'Share it with someone.',
            'text' => [
                'A playlist starts out yours alone. On its page, <strong>Share</strong> opens a dialog where you paste the public id of another account on the server and choose <strong>Add</strong>. That person now has the playlist in their own list and can play it.',
                'To let them add songs as well, switch on <strong>Let collaborators edit</strong>.',
            ],
            'figure' => [
                'picture' => 'playlists-share',
                'alt' => 'The Share playlist dialog: a Let collaborators edit switch, the list of collaborators, a field for a public id and your own public id with a Copy button.',
                'caption' => 'Share playlist · Captured in Bånder with sample music',
            ],
        ],
        [
            'id' => 'shared-with-you',
            'heading' => 'On the other side.',
            'text' => [
                'The person you shared with finds the playlist under <strong>Playlists</strong>, marked <strong>Shared with you</strong>, and plays it from the same kind of page. <strong>Leave playlist</strong> takes it off their list.',
            ],
            'figure' => [
                'picture' => 'playlists-shared',
                'alt' => "Another account's Playlists page, where one playlist carries a Shared with you label.",
                'caption' => 'Shared with you · Captured in Bånder with sample music',
            ],
        ],
    ],
    'guide' => [
        'needs' => 'A Bånder account on an instance with music in its library and, for sharing, a second account on the same instance. Bånder is pre-release and installs by building from source; the <a href="/docs/operator-guide/getting-started/installation/">installation guide</a> has the steps.',
        'steps' => [
            ['title' => 'Create a playlist', 'text' => 'Choose <strong>Playlists</strong> in the sidebar and press the <strong>+</strong> beside the heading. Type a name and choose <strong>Create</strong>.'],
            ['title' => 'Add songs', 'text' => 'Open an album, right-click a song, point to <strong>Add to Playlist</strong> and choose your playlist.'],
            ['title' => 'Play it', 'text' => 'Open the playlist from <strong>Playlists</strong> and choose <strong>Play all</strong>.'],
            ['title' => 'Share it', 'text' => "Choose <strong>Share</strong>, paste the other person's public id and choose <strong>Add</strong>. They find their id under <strong>Your public id</strong> when they open <strong>Share</strong> on a playlist of their own."],
        ],
    ],
    'limits' => [
        'The create form has a <strong>Smart playlist</strong> switch. Bånder stores the rules entered there and does not evaluate them, so the rules select no music.',
        'The web application adds songs to a playlist. It has no control for removing a song or changing the order.',
        'After you create a playlist on the Playlists page, the list of playlists can stay blank until you open the page again.',
        'You share a playlist with specific accounts on the same server, one public id at a time.',
        'Only the owner deletes a playlist or decides who it is shared with.',
        'A collaborator can add only songs that their own library access lets them see, and in a shared playlist, each person sees the songs their libraries include.',
    ],
    'manual' => [
        ['label' => 'Operator guide: who can share a playlist', 'href' => '/docs/operator-guide/administration/sessions-and-access/#who-can-change-the-catalog-and-who-can-share-a-playlist'],
    ],
];
