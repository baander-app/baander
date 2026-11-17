<?php

declare(strict_types=1);

return [
    'name' => 'Equalizer',
    'summary' => 'Shape the sound for your headphones and your speakers, and keep a profile for each.',
    'description' => "Bånder's equalizer: presets, ten bands or parametric control, compression, stereo width and crossfeed, A/B comparison and a profile for each device.",
    'headline' => 'Find your sound.',
    'lead' => [
        "Headphones and speakers don't sound alike, so Bånder lets you keep a sound for each. Start from a preset or shape it yourself, compare two versions, and save the one you like as that device's profile.",
    ],
    'hero' => [
        'picture' => 'equalizer',
        'alt' => "Bånder's equalizer page with presets, ten frequency bands and the input analyzer.",
        'caption' => 'The equalizer · Captured in Bånder',
    ],
    'tour' => [
        [
            'id' => 'parametric',
            'heading' => 'See the shape of your EQ.',
            'text' => [
                "Switch to the parametric view to work with the bands on a response graph. Drag a point to change its frequency and gain, and read the selected band's frequency, gain and Q beside it.",
                'The curve draws all the bands together, so a boost in one place and a cut in another show in one view.',
            ],
            'figure' => [
                'picture' => 'equalizer-parametric',
                'alt' => 'The parametric equalizer: a response curve with a point for each band.',
                'caption' => 'Parametric EQ · Captured in Bånder',
            ],
        ],
        [
            'id' => 'compare-and-keep',
            'heading' => 'Hear the difference.<br>Keep what works.',
            'text' => [
                'Capture your settings as A, change something, capture B, and switch between the two to compare.',
                'When it sounds right, save it to a named device profile: one for your headphones, another for your speakers. Activate the profile for whatever you are listening on.',
            ],
            'figure' => [
                'picture' => 'equalizer-profiles',
                'alt' => 'The A/B comparison slots and the list of named device profiles.',
                'caption' => 'A/B comparison and device profiles · Captured in Bånder',
            ],
        ],
    ],
    'guide' => [
        'needs' => 'A Bånder instance with music in its library. Bånder is pre-release and installs by building from source; the <a href="/docs/operator-guide/getting-started/installation/">installation guide</a> has the steps.',
        'steps' => [
            ['title' => 'Play a song', 'text' => 'Start any track, so there is sound to shape.'],
            ['title' => 'Open the equalizer', 'text' => 'Choose <strong>Equalizer</strong> at the bottom of the sidebar.'],
            ['title' => 'Start from a preset', 'text' => 'Pick one under <strong>Presets</strong>, then move the bands until it sounds right.'],
            ['title' => 'Save a profile', 'text' => 'Under <strong>Device Profiles</strong>, name a profile and save your current settings to it.'],
        ],
    ],
    'limits' => [
        "The equalizer works in the browser that plays the music. When you move playback to another device, this browser's EQ does not apply there.",
        'The input analyzer shows the signal before EQ and gain. It does not measure what your headphones or speakers put out.',
    ],
    'manual' => [
        ['label' => 'Developer guide: user preferences', 'href' => '/docs/developer-guide/contexts/user-preference/'],
    ],
];
