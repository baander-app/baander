<?php

declare(strict_types=1);

return [
    'name' => 'Playback destinations',
    'summary' => 'Start music in one browser and carry on in another that is signed in to your account.',
    'description' => "Bånder's playback destinations: move what is playing to another browser signed in to your account, control it from the first, and bring it back.",
    'headline' => 'Start in one room.<br>Keep listening in another.',
    'lead' => [
        'You start an album at your desk and want to hear the rest in the kitchen. Any browser tab signed in to your Bånder account can take over: the song, the queue and the position move to it, and so does whether it was playing or paused.',
    ],
    'hero' => [
        'picture' => 'destinations-window',
        'alt' => 'Bånder with the playback destination menu open: this browser, the other signed-in browsers by name with one marked active, and an entry that returns playback to this browser.',
        'caption' => 'Destinations · Captured in Bånder with sample music',
    ],
    'tour' => [
        [
            'id' => 'move',
            'heading' => 'Send it to another browser.',
            'text' => [
                'The <a href="/features/player/">player</a> bar has a small screen icon beside the volume. It opens a menu of the places your account can play right now: this browser, and every other browser tab where you are signed in.',
                'Choose one. The music stops here and carries on there, from the same position in the same queue.',
            ],
            'figure' => [
                'picture' => 'destinations-menu',
                'alt' => 'The playback destination menu before a move: this browser marked active, and the other signed-in browsers listed by name.',
                'caption' => 'Before the move · Captured in Bånder with sample music',
            ],
        ],
        [
            'id' => 'control-and-return',
            'heading' => 'Keep the controls.<br>Bring it back.',
            'text' => [
                'After the move, the first browser still shows the song. Pause it there, and the other browser follows.',
                'When you are back at your desk, open the menu and choose <strong>Return playback to this browser</strong>.',
            ],
            'figure' => [
                'picture' => 'destinations-return',
                'alt' => 'The playback destination menu after a move: another browser marked active, the Return playback to this browser entry and a note about sound processing.',
                'caption' => 'After the move · Captured in Bånder with sample music',
            ],
        ],
    ],
    'guide' => [
        'needs' => 'Two browsers signed in to the same Bånder account on an instance with music in its library. Bånder is pre-release and installs by building from source; the <a href="/docs/operator-guide/getting-started/installation/">installation guide</a> has the steps.',
        'steps' => [
            ['title' => 'Sign in twice', 'text' => 'Open Bånder in a second browser, sign in with the same account and leave it open.'],
            ['title' => 'Play a song', 'text' => 'In the first browser, start any track. If a <strong>Settings conflict</strong> dialog appears in either browser, choose one of its two answers to close it.'],
            ['title' => 'Open the destination menu', 'text' => 'In the player bar, press the screen icon beside the volume. The menu is headed <strong>Playback destination</strong>.'],
            ['title' => 'Pick the other browser', 'text' => 'It is listed by its browser and system, for example <em>Firefox on Linux</em>. The song carries on there.'],
            ['title' => 'Bring it back', 'text' => 'Open the menu again and choose <strong>Return playback to this browser</strong>.'],
        ],
    ],
    'planned' => [
        ['title' => 'Casting to receivers', 'text' => 'Casting to AirPlay, Chromecast and DLNA receivers is planned and not available yet.'],
    ],
    'limits' => [
        'A destination is a browser tab that is open and signed in to your account. Music cannot move to a browser that is closed or signed out.',
        "Sound processing and volume depend on the browser that plays. This browser's <a href=\"/features/equalizer/\">equalizer</a> does not apply to music playing in another one.",
        'A second browser signed in to the same account can show a <strong>Settings conflict</strong> dialog. Choose either answer to carry on.',
        'When the browser that is playing crashes or loses its connection, the others wait about 30 seconds before the menu offers <strong>Play here anyway</strong>.',
        'A closing page reports to the server that it has stopped, but the server can refuse the report. The other browsers then offer <strong>Play here anyway</strong>, as after a crash.',
        'The close report and <strong>Play here anyway</strong> are tested in Chromium only. Safari does not resume its own playback after a reload.',
    ],
    'manual' => [
        ['label' => 'Developer guide: sessions and playback transfer', 'href' => '/docs/developer-guide/contexts/session/'],
    ],
];
