<?php

declare(strict_types=1);

return [
    'name' => 'Console',
    'summary' => 'Run every action of the administration pages, and more, as a command in a shell or a script.',
    'description' => "Bånder's console: 153 documented commands for libraries, users, metadata, jobs and the server, run in the application container with full authority.",
    'headline' => 'Every admin action,<br>from a shell.',
    'lead' => [
        "You are already in a terminal on the server, and the browser is the long way round. Bånder documents 153 console commands of its own, grouped in 14 families, and every action on its administration pages has a console command. A single line scans every library or checks the server's health.",
    ],
    'hero' => [
        'manual' => 'part-1-operator-guide/commands/app-library-scan.md',
        'contains' => 'app:library:scan --all',
    ],
    'tour' => [
        [
            'id' => 'same-actions',
            'heading' => 'What a page does, a command does.',
            'text' => [
                'Add a library in the browser or run <code>app:library:create</code>: both run the same use case, with the same validation and the same conflicts. Every action on the <a href="/features/administration/">administration pages</a> has a command like it, so what you can click you can also type.',
            ],
            'figure' => [
                'manual' => 'part-1-operator-guide/commands/app-library-create.md',
                'contains' => 'music music --slug',
            ],
            'layout' => 'wide',
        ],
        [
            'id' => 'full-authority',
            'heading' => 'The shell has full authority.',
            'text' => [
                'The administration pages check your role before each action. A console command checks none, so an operator who is locked out of the browser can still create a super administrator.',
                'It is also why console access belongs only to people you trust with the whole installation.',
            ],
            'figure' => [
                'manual' => 'part-1-operator-guide/commands/app-user-create.md',
                'contains' => '--role=super-admin --force',
            ],
            'layout' => 'wide',
        ],
        [
            'id' => 'scripts',
            'heading' => 'Ready for a script.',
            'text' => [
                'A script has no terminal to answer a prompt, so <code>app:user:create</code> takes its answers another way: <code>--password</code> reads the password from standard input, <code>--force</code> grants an admin role without asking, and <code>--json</code> prints only the new user.',
            ],
            'figure' => [
                'manual' => 'part-1-operator-guide/commands/app-user-create.md',
                'contains' => 'Alice --password --json',
            ],
            'layout' => 'wide',
        ],
        [
            'id' => 'health',
            'heading' => 'A first check in one line.',
            'text' => [
                'When something seems wrong, <code>app:health:check</code> checks the database, Redis and the other components, and prints a table with the status and latency of each.',
            ],
            'figure' => [
                'manual' => 'part-1-operator-guide/commands/app-health-check.md',
                'contains' => 'app:health:check',
            ],
            'layout' => 'wide',
        ],
    ],
    'guide' => [
        'needs' => 'Shell access to the Docker host of a running Bånder instance. Bånder is pre-release and installs by building from source; the <a href="/docs/operator-guide/getting-started/installation/">installation guide</a> has the steps.',
        'steps' => [
            ['title' => "Open the installation's shell", 'text' => 'On the Docker host, change to the installation\'s checkout and define the <code>dc</code> function as the installation guide\'s <a href="/docs/operator-guide/getting-started/installation/#command-context">command context</a> describes.'],
            ['title' => 'Run a command', 'text' => 'Enter <code>dc exec app php bin/console app:health:check</code>. <code>dc exec app</code> runs what follows inside the application container.'],
            ['title' => 'Read the result', 'text' => 'The table lists each component with <code>OK</code>, <code>FAIL</code> or <code>N/A</code> and its latency.'],
            ['title' => 'Find the next command', 'text' => 'Add <code>--help</code> to a command to see its arguments and options, or look it up by family in the <a href="/docs/operator-guide/commands/">command reference</a>.'],
        ],
    ],
    'limits' => [
        'Console commands need trusted shell access to the installation. They apply no role check, so anyone with that access can do everything.',
        'Commands run inside the application container, and the paths they take are container paths. The worker pair is operated from the host through its own entry point.',
        'Most reference pages show their examples as <code>make exec cmd="php bin/console …"</code>, which targets a development preview. On an installed instance, run the same arguments with <code>dc exec app php bin/console …</code>.',
        'The 153 include five commands for development and documentation and a few you do not run yourself, such as <code>app:worker</code>.',
        "The exit status of <code>app:health:check</code> describes the web container; it leaves out the background worker.",
    ],
    'manual' => [
        ['label' => 'Command reference', 'href' => '/docs/operator-guide/commands/'],
        ['label' => 'Installation: command context', 'href' => '/docs/operator-guide/getting-started/installation/#command-context'],
    ],
];
