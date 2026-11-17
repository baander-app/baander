<?php

declare(strict_types=1);

return [
    'description' => "A tour of Bånder's features for listeners, operators and developers: pictures from the application, short guides and each feature's limits.",
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
            'pages' => ['equalizer'],
        ],
        [
            'id' => 'developers',
            'title' => 'For building on it',
            'lead' => 'See how the code is organized and try a change.',
            'pages' => ['developers'],
        ],
    ],
];
