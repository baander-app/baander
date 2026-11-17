<?php

declare(strict_types=1);

return [
    'name' => 'Developers',
    'summary' => 'Find your way around the code, then try a change in a preview of its own.',
    'description' => 'How Bånder is built: 21 bounded contexts, a REST API with a generated TypeScript client, live updates over one WebSocket, previews and tests.',
    'headline' => 'Find your way around.',
    'lead' => [
        "You open a codebase and want to know where things live before you change anything. Bånder's backend is divided into 21 bounded contexts, each a business capability with its own rules, and most are laid out in the same four layers.",
    ],
    'hero' => [
        'manual' => 'part-2-developer-guide/architecture/overview.md',
        'contains' => 'accTitle: The four layers of a bounded context and the Shared kernel',
    ],
    'tour' => [
        [
            'id' => 'contexts',
            'heading' => 'One context at a time.',
            'text' => [
                'Say you want to change how playlists work. The code is in <code>src/Playlist/</code>, one of the 21 contexts, and it knows nothing about transcoding. Contexts talk to each other through asynchronous commands, application ports and domain events.',
                'A context with the full structure has four layers: Domain for business rules, Application for use cases, Infrastructure for repositories and external adapters, and Interface for controllers and console commands. A few utility contexts leave out the layers they do not need.',
            ],
            'figure' => [
                'manual' => 'part-2-developer-guide/architecture/overview.md',
                'contains' => 'src/<Context>/',
            ],
            'layout' => 'wide',
        ],
        [
            'id' => 'generated-client',
            'heading' => 'The client is generated.',
            'text' => [
                "Add an endpoint, and the web client has to learn its shape. The REST API's OpenAPI description is generated from the attributes on controllers, request DTOs and response resources, and Orval turns it into typed React Query hooks and TypeScript types.",
                'A component imports a hook and calls it. After you change the API, export the description and run <code>yarn generate</code>; <code>yarn generate:check</code> fails when the committed client differs.',
            ],
            'figure' => [
                'manual' => 'part-2-developer-guide/development/frontend-development.md',
                'contains' => 'useGetArtistIndex',
            ],
            'layout' => 'wide',
        ],
        [
            'id' => 'live-updates',
            'heading' => 'One socket for live updates.',
            'text' => [
                'A page is open, and its data changes on the server. Once the change commits, the server sends a hint over the one WebSocket connection the signed-in application holds, and the browser reads the data again over HTTP.',
                'The hint names what to reread and carries none of its content, so each read still passes its normal authorization. A feature joins in by describing its queries; it adds no socket, polling timer or recovery loop of its own.',
            ],
        ],
        [
            'id' => 'previews-and-tests',
            'heading' => 'A preview for every worktree.',
            'text' => [
                'You have a change on a branch and want to see it run. <code>make preview-up</code> starts an instance for your worktree alone, with its own hostname, database, Redis, keys and storage, and PHP and Vite read your files as you edit them.',
                'Tests are isolated too. Four backend PHPUnit suites (Unit, StaticAnalysisRules, Functional and Integration) run in disposable containers, and a fifth, Tooling, covers the build and deployment tooling. Vitest runs the web unit tests, and Playwright drives a browser against your preview.',
            ],
            'figure' => [
                'manual' => 'part-2-developer-guide/testing/README.md',
                'contains' => 'bash scripts/test-backend-container.sh --testsuite Unit,StaticAnalysisRules',
            ],
            'layout' => 'wide',
        ],
    ],
    'guide' => [
        'needs' => 'A clone of the repository on a Linux host with PHP 8.5, Composer and Docker. The <a href="/docs/developer-guide/getting-started/quick-start/#prerequisites">quick start</a> lists every prerequisite.',
        'steps' => [
            ['title' => 'Install the host controller', 'text' => 'From the repository root, run <code>composer --working-dir=scripts install --no-dev</code>.'],
            ['title' => 'Set up local names and certificates', 'text' => 'Run <code>make dev-host-setup</code> and follow the quick start for the DNS files and the local certificate authority.'],
            ['title' => 'Start your preview', 'text' => 'Run <code>make preview-up</code>, then <code>make preview-url</code>, and open the address it prints.'],
            ['title' => 'Change something', 'text' => 'Edit a file under <code>ui/web/</code> and Vite updates the browser; edit a PHP file and it reloads on save.'],
            ['title' => 'Run the checks', 'text' => 'Before you open a pull request, the <a href="/docs/developer-guide/contributing/contributing/#pull-request-process">contribution guide</a> names the checks to run.'],
        ],
    ],
    'limits' => [
        'The development environment runs on Linux hosts.',
        'The Swagger UI route is disabled; the OpenAPI description is served as JSON at <code>/api/doc.json</code> and tracked as <code>openapi.json</code>.',
        'Access tokens are DPoP-bound: API requests send the token with a proof from the same key.',
        'A user can hold at most 10 WebSocket connections at once, and each connection may send 30 messages per second.',
        'In a preview, the background workers run a snapshot of the code taken by the last <code>make preview-up</code> or <code>make restart-app</code>, and a new preview starts with an empty catalog.',
        'Bånder is pre-release. While that holds, reviewers favor the intended design over backward compatibility.',
    ],
    'manual' => [
        ['label' => 'Developer guide: quick start', 'href' => '/docs/developer-guide/getting-started/quick-start/'],
        ['label' => 'Developer guide: architecture overview', 'href' => '/docs/developer-guide/architecture/overview/'],
        ['label' => 'Developer guide: API reference', 'href' => '/docs/developer-guide/development/api-reference/'],
        ['label' => 'Developer guide: live updates', 'href' => '/docs/developer-guide/architecture/live-updates/'],
        ['label' => 'Developer guide: contributing', 'href' => '/docs/developer-guide/contributing/contributing/'],
    ],
];
