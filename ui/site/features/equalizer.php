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
        'picture' => 'equalizer-window',
        'alt' => "Bånder's equalizer page: the input analyzer showing a spectrum, the presets and the device profiles, with the player bar along the bottom.",
        'caption' => 'The equalizer · Captured in Bånder with sample music',
    ],
    'tour' => [
        [
            'id' => 'bands',
            'heading' => 'A starting point.<br>Then your own adjustments.',
            'text' => [
                'Choose a preset and move the ten bands until it sounds right. Then capture your settings as A, change something, capture B, and switch between the two to compare.',
            ],
            'figure' => [
                'picture' => 'equalizer-compare',
                'alt' => 'The ten frequency bands with a preset adjusted, above the A/B Compare card with both slots filled.',
                'caption' => 'Bands and A/B · Captured in Bånder with sample music',
            ],
            'layout' => 'wide',
        ],
        [
            'id' => 'parametric',
            'heading' => 'See the shape of your EQ.',
            'text' => [
                "Switch to the parametric view to work with the bands on a response graph. Drag a point to change its frequency and gain, and read the selected band's frequency, gain and Q beside it.",
                'The curve draws all the bands together, so a boost in one place and a cut in another show in one view.',
            ],
            'figure' => [
                'picture' => 'equalizer-curve',
                'alt' => 'The parametric equalizer: a response curve with a point for each band and a readout of the selected band.',
                'caption' => 'Parametric EQ · Captured in Bånder with sample music',
            ],
            'layout' => 'wide',
        ],
        [
            'id' => 'processing',
            'heading' => 'More than frequency bands.',
            'text' => [
                "Set the compressor's threshold, ratio, knee, attack and release. Change the stereo width, or switch on crossfeed for headphone listening.",
                'A loudness contour and master gain, with optional normalization to a loudness target, set the level.',
            ],
            'figure' => [
                'picture' => 'equalizer-dynamics',
                'alt' => 'The Processing card: a compressor with five sliders, stereo width, crossfeed, loudness and master gain with normalization.',
                'caption' => 'Processing · Captured in Bånder with sample music',
            ],
        ],
        [
            'id' => 'profiles',
            'heading' => 'Keep what works.',
            'text' => [
                'When it sounds right, save it to a named device profile: one for your headphones, another for your speakers. Activate the profile for whatever you are listening on.',
            ],
            'figure' => [
                'picture' => 'equalizer-device-profiles',
                'alt' => 'The Device Profiles card listing two named profiles.',
                'caption' => 'Device profiles · Captured in Bånder with sample music',
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
