<?php

declare(strict_types=1);

// The design system section: the overview, then its pages in the order the section navigation lists
// them. Each page's values are read from the design tokens when the site builds; `borrow` names the
// sections of the design documents a page publishes as they stand.
return [
    'overview' => [
        'title' => 'Design system',
        'description' => 'Bånder’s design system: its principles, colors, accents, type roles, layout, motion, icons, writing rules and logo, each value read from its source.',
        'opening' => 'The design system is the set of rules and values Bånder’s interface is built from. Each page shows one part of it, with values read from the design tokens and rules quoted from the design language when this site is built.',
        'borrow' => [
            ['id' => 'philosophy', 'document' => 'ui/DESIGN.md', 'headings' => ['Philosophy']],
        ],
    ],
    'pages' => [
        [
            'slug' => 'color',
            'title' => 'Color',
            'summary' => 'The semantic color roles in each of the four moods, with live swatches.',
            'description' => 'Every semantic color role of Bånder’s interface, with its use and its value in the Dark, Warm, Cool and Balanced moods, shown as live swatches.',
            'opening' => 'Interface code names a color by its role, such as primary text or a subtle surface, and the selected mood gives each role its value. The swatches show the application’s values at the default accent.',
            'borrow' => [
                ['id' => 'token-authority', 'document' => 'ui/DESIGN.md', 'headings' => ['Color', 'Token authority'], 'tables' => false],
            ],
        ],
        [
            'slug' => 'accents',
            'title' => 'Accents',
            'summary' => 'The eight accents and the colors each mood derives from them, with contrast ratios.',
            'description' => 'Bånder’s eight accent colors and the primary, accent text and focus ring each mood derives from them, with WCAG 2 contrast ratios computed at build time.',
            'opening' => 'An accent is the identity color a user picks. Each mood derives its filled actions, accent text and focus ring from it, moving each toward white or black until it reaches the contrast the design tokens require on every surface.',
            'borrow' => [
                ['id' => 'dynamic-accent', 'document' => 'ui/DESIGN.md', 'headings' => ['Color', 'Dynamic accent (blurhash-derived)']],
                ['id' => 'accent-contrast', 'document' => 'ui/DESIGN.md', 'headings' => ['Color', 'Foreground-on-accent contrast']],
            ],
        ],
        [
            'slug' => 'type',
            'title' => 'Type',
            'summary' => 'The ten type roles with their sizes, weights, tracking and line-heights, and live samples.',
            'description' => 'The ten type roles of Bånder’s interface with desktop and phone sizes, weights, tracking, line-heights and uses, each set as a live sample.',
            'opening' => 'Every text element uses one of ten type roles. The table gives each role’s values from the design tokens, and each sample is set in its role. Scripts, glyph forms and script rules are on the <a href="/typography/">typography page</a>.',
            'borrow' => [
                ['id' => 'roles', 'document' => 'ui/DESIGN.md', 'headings' => ['Typography', 'Roles'], 'tables' => false],
            ],
        ],
        [
            'slug' => 'layout',
            'title' => 'Layout',
            'summary' => 'Spacing, radii, surfaces, the app shell and the well.',
            'description' => 'The spacing and radius scales of Bånder’s interface with their documented uses, the app shell’s measurements, the surface rules and the well.',
            'opening' => 'Layout values come from two scales, spacing and radius, each with a short list of uses. Surfaces stay flat, with one exception: the well, a recessed face that one surface holds at a time.',
            'borrow' => [
                ['id' => 'app-shell', 'document' => 'ui/DESIGN.md', 'headings' => ['Layout', 'App Shell']],
                ['id' => 'surfaces', 'document' => 'ui/DESIGN.md', 'headings' => ['Layout', 'Surfaces']],
                ['id' => 'border-radius', 'document' => 'ui/DESIGN.md', 'headings' => ['Layout', 'Border radius']],
                ['id' => 'spacing', 'document' => 'ui/DESIGN.md', 'headings' => ['Layout', 'Spacing']],
                ['id' => 'footer-player', 'document' => 'ui/DESIGN.md', 'headings' => ['Layout', 'Footer player']],
                ['id' => 'notification-pane', 'document' => 'ui/DESIGN.md', 'headings' => ['Layout', 'Notification pane']],
            ],
        ],
        [
            'slug' => 'motion',
            'title' => 'Motion',
            'summary' => 'How long each transition takes, its easing, and the duration tokens.',
            'description' => 'How long each of Bånder’s interface transitions takes, the one easing they all use, and where each duration token is used.',
            'opening' => 'Motion shows where things went. Each transition has a fixed duration and eases out, and the hover state takes its duration from a design token.',
            'borrow' => [
                ['id' => 'motion', 'document' => 'ui/DESIGN.md', 'headings' => ['Motion'], 'tables' => false],
            ],
        ],
        [
            'slug' => 'iconography',
            'title' => 'Iconography',
            'summary' => 'When to use an icon, and which icon each concept uses.',
            'description' => 'When Bånder’s interface uses icons, how many a menu may hold, and the Lucide icon assigned to each concept.',
            'opening' => 'Icons highlight the few options a reader should find first; they do not decorate. Each concept has one assigned Lucide icon, used wherever that concept appears.',
            'borrow' => [
                ['id' => 'when-to-use-icons', 'document' => 'ui/ICON-GUIDE.md', 'headings' => ['When to Use Icons']],
                ['id' => 'icons-in-menus', 'document' => 'ui/DESIGN.md', 'headings' => ['Components', 'Icons in menu lists']],
                ['id' => 'assignments', 'document' => 'ui/ICON-GUIDE.md', 'headings' => ['Canonical Concept to Icon Assignments']],
            ],
        ],
        [
            'slug' => 'writing',
            'title' => 'Writing',
            'summary' => 'How interface text is written, and what loading, error and empty states show.',
            'description' => 'How Bånder’s interface text is written: plain words, sentence case, errors that say what to do next, and the rules for loading, error and empty states.',
            'opening' => 'These rules cover every string the interface shows, from button labels to error messages, and what a screen shows while it loads, when it fails and when it has nothing to show.',
            'borrow' => [
                ['id' => 'writing', 'document' => 'ui/DESIGN.md', 'headings' => ['Writing']],
                ['id' => 'loading-states', 'document' => 'ui/DESIGN.md', 'headings' => ['Components', 'Loading states']],
                ['id' => 'error-states', 'document' => 'ui/DESIGN.md', 'headings' => ['Components', 'Error states']],
                ['id' => 'empty-states', 'document' => 'ui/DESIGN.md', 'headings' => ['Components', 'Empty states']],
                ['id' => 'content-language', 'document' => 'ui/DESIGN.md', 'headings' => ['Typography', 'Content language'], 'from' => 'Put one content item'],
            ],
        ],
        [
            'slug' => 'logo',
            'title' => 'Logo',
            'summary' => 'The cassette mark at the sizes each version is drawn for.',
            'description' => 'Bånder’s cassette mark in its three versions, from full detail to simplified, each shown at the sizes it is drawn for.',
            'opening' => 'The mark is a cassette. It comes in three versions with less detail as the size drops, and each file states the sizes it is drawn for.',
        ],
        [
            'slug' => 'website',
            'title' => 'Website',
            'summary' => 'Where this website departs from the application’s design.',
            'description' => 'Where Bånder’s public website departs from the application’s design: body text, eyebrow labels, two moods, a softer dark foreground and picture sizes.',
            'opening' => 'This website follows the application’s design language, with changes for long reading and static pages. The table lists them with the values the site’s stylesheets and theme set.',
            'borrow' => [
                ['id' => 'static-public-website', 'document' => 'ui/DESIGN.md', 'headings' => ['Static public website']],
            ],
        ],
    ],
];
