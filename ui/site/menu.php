<?php

declare(strict_types=1);

// The top menu's entries besides the feature pages, which bring their own name and summary. Each
// description says in one line what the page holds.
return [
    'features' => 'Every feature page, grouped by who it is for.',
    'product' => [
        [
            'title' => 'Audio Core',
            'url' => '/bac/',
            'description' => 'The AAC and FLAC codec library, with recorded quality, size and speed comparisons.',
        ],
        [
            'title' => 'Strategy',
            'url' => '/strategy/',
            'description' => 'Who Bånder is for, what is built, the phases planned for further media types and what stays out of scope.',
        ],
    ],
    'resources' => [
        [
            'title' => 'Run it',
            'entries' => [
                [
                    'title' => 'Operator manual',
                    'url' => '/docs/operator-guide/',
                    'description' => 'Install, configure and maintain a Bånder instance.',
                ],
                [
                    'title' => 'Deploy preset',
                    'url' => '/deploy/',
                    'description' => 'Size and start a deployment from one JSON file, with the example presets compared.',
                ],
            ],
        ],
        [
            'title' => 'Build on it',
            'entries' => [
                [
                    'title' => 'Developer guide',
                    'url' => '/docs/developer-guide/',
                    'description' => 'The architecture, the project conventions, the tests and how to contribute.',
                ],
                [
                    'title' => 'Concepts',
                    'url' => '/concepts/',
                    'description' => 'The terms the code and documentation use, each with its meaning in Bånder.',
                ],
                [
                    'title' => 'Typography',
                    'url' => '/typography/',
                    'description' => 'The fonts, glyph forms, script rules and type roles for every supported script.',
                ],
            ],
        ],
        [
            'title' => 'Open source',
            'entries' => [
                [
                    'title' => 'Third-party licensing',
                    'url' => '/licensing/',
                    'description' => 'The licenses that cover Bånder and where the notices of bundled libraries and fonts are kept.',
                ],
            ],
        ],
    ],
];
