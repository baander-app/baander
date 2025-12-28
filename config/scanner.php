<?php

return [
    'music' => [
        'directory_chunk_size' => env('MUSIC_SCANNER_DIRECTORY_CHUNK_SIZE', 10),

        'rate_limiting' => [
            // Jobs per second limit for external API sync jobs (MusicBrainz, Discogs, etc.)
            'sync_jobs_per_second' => env('METADATA_SYNC_RATE_LIMIT', 1),
        ],

        'delimiter_rules' => [
            'problematic' => [
                'skip_slash' => ['AC/DC', 'M/F', 'R.E.M./Live']
            ],

            // Known artist names that contain delimiters (shouldn't split)
            // Sources: Navidrome, Jellyfin, Kodi community discussions, MusicBrainz
            'known_artist_exceptions' => [
                // Rock bands with slashes
                'AC/DC',
                'M/F',
                'T. Rex',
                'R.E.M.',
                'Deep Blue Something',
                'The Jimi Hendrix Experience',

                // Bands with commas (actual names, not locations)
                'Earth, Wind & Fire',
                'Crosby, Stills, Nash & Young',
                'Peter, Bjorn and John',
                'Adam & the Ants',
                'Booker T. & the M.G.s',
                'Emerson, Lake & Palmer',

                // Electronic/DJ duos with &
                'Daft Punk',
                'Sasha & John Digweed',
                'Shawn Mendes & Camila Cabello',
                'Lady Gaga & Bradley Cooper',
                'Zedd & Alessia Cara',
                'Simon & Garfunkel',
                'Hall & Oates',
                'OutKast',

                // Versus / Battle tracks
                'Moby vs. Fatboy Slim',
                'Britney Spears vs. Michael Jackson',
                'Eminem vs. Machine Gun Kelly',

                // Featured artists that are bands themselves
                'Alesso',
                'Clean Bandit',
                'The Chainsmokers',
                'Calvin Harris',
                'Macklemore & Ryan Lewis',
                'Drake & Future',
                'Rihanna & Calvin Harris',
                'Kanye West & Lil Pump',

                // Country duos
                'Brooks & Dunn',
                'Sugarland',
                'The Judds',
                'Porter Wagoner & Dolly Parton',
                'George Jones & Tammy Wynette',
                'Johnny Cash & June Carter',
                'Florida Georgia Line',

                // Jazz ensembles
                'The Miles Davis Quintet',
                'John Coltrane & Johnny Hartman',
                'Duke Ellington & His Orchestra',
                'Count Basie & His Orchestra',

                // Classical composers
                'Wolfgang Amadeus Mozart',
                'Johann Sebastian Bach',
                'Ludwig van Beethoven',
                'Johannes Brahms',

                // Metal bands with umlauts
                'Motörhead',
                'Mötley Crüe',
                'Queensrÿche',
                'Skálmöld',
                'Helloween',
                'Blutengel',

                // Artists with periods/abbreviations
                'R.E.M.',
                'N.W.A',
                'O.A.R.',
                'M.F.A.',
                'B.A.',
                'D.R.A.M.',
                'M.F.M.',

                // Hip-hop groups
                'Cypress Hill',
                'A Tribe Called Quest',
                'De La Soul',
                'The Pharcyde',
                'Digable Planets',

                // Punk/Alternative
                'Panic! At the Disco',
                '!!!', // Chk-Chk-Chk
                'Godspeed You! Black Emperor',
                'The Mr. T Experience',

                // Latin artists
                'Manu Chao & Radio Bemba',
                'Calle 13',
                'Los Fabulosos Cadillacs',
                'Café Tacvba',
                'Mano Negra',

                // K-pop/J-pop with delimiters
                'BTS (防弹少年团)',
                'SEVENTEEN (세븐틴)',
                'TWICE (트와이스)',
                'EXO-K & EXO-M',

                // Prog rock
                'The Alan Parsons Project',
                'Trans-Siberian Orchestra',
                'Electric Light Orchestra',

                // R&B/Soul groups
                'The Isley Brothers',
                'The O\'Jays',
                'Boyz II Men',
                'Smokey Robinson & The Miracles',
            ],
        ],
    ],
];