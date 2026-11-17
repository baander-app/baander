<?php

declare(strict_types=1);

return [
    'name' => 'Player',
    'summary' => 'A player that stays with you while you browse, with a queue you can see and rearrange.',
    'description' => "Bånder's web player keeps playing while you browse, shows its queue and lets you choose what plays next.",
    'headline' => 'Look around.<br>The music plays on.',
    'lead' => [
        "Halfway through a song you think of the one you want next. Bånder's player stays at the bottom of the window while you browse, so you can go and find it without stopping what is playing, then put it next in the queue.",
    ],
    'hero' => [
        'picture' => 'player',
        'alt' => 'Bånder with a grid of albums in the middle, the queue in a panel on the right and the player bar along the bottom.',
        'caption' => 'The player · Captured in Bånder with sample music',
    ],
    'tour' => [
        [
            'id' => 'queue',
            'heading' => 'See what is coming.',
            'text' => [
                'The <strong>Queue</strong> tab in the panel on the right lists what will play, with the current song marked. Start an album and its tracks are the queue. Start one song from a list and the rest of the list comes with it.',
                'Drag a waiting song by its handle to move it, click a title to jump to it, or take a song out with the × on its row.',
            ],
            'figure' => [
                'picture' => 'player-queue',
                'alt' => 'The Queue tab: a count of tracks, a Clear all button and the queued songs in order, with the current one marked.',
                'caption' => 'The queue · Captured in Bånder with sample music',
            ],
        ],
        [
            'id' => 'play-next',
            'heading' => 'Choose what plays next.',
            'text' => [
                "Right-click an album's cover, or a song on an album's page, and choose <strong>Play Next</strong>. It goes in straight after the song that is playing, and the rest of the queue moves down.",
                '<strong>Play Last</strong> puts it at the end of the queue.',
            ],
            'figure' => [
                'picture' => 'player-next',
                'alt' => 'The menu that opens with a right-click on an album cover, with Play All, Shuffle All, Play Next and Play Last at the top.',
                'caption' => "An album's menu · Captured in Bånder with sample music",
            ],
        ],
        [
            'id' => 'shuffle',
            'heading' => 'Shuffle it.',
            'text' => [
                "Start an album shuffled with <strong>Shuffle All</strong> in its menu, or an artist with the <strong>Shuffle</strong> button on the artist's page.",
                'While music plays, <kbd>S</kbd> switches shuffle on and off, and <kbd>R</kbd> steps through repeat: the whole queue, one song, off.',
            ],
            'figure' => [
                'picture' => 'player-shuffle',
                'alt' => "An artist's page: the artist's name, Play and Shuffle buttons, and the covers of their albums.",
                'caption' => "An artist's page · Captured in Bånder with sample music",
            ],
            'layout' => 'wide',
        ],
    ],
    'guide' => [
        'needs' => 'A Bånder instance with music in its library. Bånder is pre-release and installs by building from source; the <a href="/docs/operator-guide/getting-started/installation/">installation guide</a> has the steps.',
        'steps' => [
            ['title' => 'Start something', 'text' => 'Click an album cover and choose <strong>Play</strong>, or double-click a track.'],
            ['title' => 'Look at the queue', 'text' => 'It is under <strong>Queue</strong> in the panel on the right. If you closed the panel, press <kbd>Q</kbd> to bring it back.'],
            ['title' => 'Line up the next song', 'text' => "Right-click an album's cover and choose <strong>Play Next</strong>."],
            ['title' => 'Rearrange', 'text' => 'Drag a waiting song by its handle to move it, or press the × on its row to take it out.'],
        ],
    ],
    'limits' => [
        'The player bar has no shuffle or repeat button while music plays. The <kbd>S</kbd> and <kbd>R</kbd> keys set them, and nothing on screen shows their state.',
        "The player asks your browser what it can play before it loads a track. A file the browser cannot play needs the server's audio transcoding, which is off by default.",
        'You cannot jump to another position in a track while the server is still converting it.',
    ],
    'manual' => [
        ['label' => 'Operator guide: audio transcoding and the web player', 'href' => '/docs/operator-guide/media/transcoding/#web-player'],
    ],
];
