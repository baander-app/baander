<?php

declare(strict_types=1);

return [
    'name' => 'Administration',
    'summary' => 'Follow background jobs from the browser, and manage schedules, accounts, library access and server settings there.',
    'description' => "Bånder's administration pages: the job monitor, the scheduler, user accounts and library access, server settings, and health alerts in the app.",
    'headline' => 'See what the server<br>is doing.',
    'lead' => [
        "Someone at home wants to know why last night's album still isn't showing up. Bånder's administration pages let you look: they list background jobs such as scans with their status, and hold the schedules, accounts and server settings.",
    ],
    'hero' => [
        'picture' => 'administration',
        'alt' => 'The Jobs tab of the administration: counts of jobs by status above a list of jobs, each with its name, status, queue and duration.',
        'caption' => 'The job monitor · Captured in Bånder',
    ],
    'tour' => [
        [
            'id' => 'jobs',
            'heading' => 'Follow a job from queued to finished.',
            'text' => [
                'Start a scan in the browser and it shows up on the <strong>Jobs</strong> tab: queued, then running, then finished, with how long it took. Select a job for its details; a failed job names its exception and offers <strong>Retry Job</strong>.',
            ],
            'figure' => [
                'picture' => 'administration-job',
                'alt' => 'The detail panel of one job, with its status, queue, job ID, attempt, timestamps and duration.',
                'caption' => 'One job in detail · Captured in Bånder',
            ],
        ],
        [
            'id' => 'scheduler',
            'heading' => 'Work that repeats itself.',
            'text' => [
                'Some work should happen whether or not anyone remembers it. The <strong>Scheduler</strong> tab lists every scheduled job with its cron expression, the command it runs, its status, and its last and next run.',
                'Pause one, change its schedule, run it now, or create another from the commands Bånder offers for scheduling.',
            ],
            'figure' => [
                'picture' => 'administration-scheduler',
                'alt' => 'The Scheduler tab: a table of scheduled jobs with columns for name, cron expression, command, status, last run and next run.',
                'caption' => 'Scheduled jobs · Captured in Bånder',
            ],
            'layout' => 'wide',
        ],
        [
            'id' => 'access',
            'heading' => 'Decide who sees which library.',
            'text' => [
                'Accounts are listed under <strong>Security</strong>. What an account may listen to is a separate matter: administrators see every library, and other users see only the libraries they have been granted.',
                'A super administrator opens <strong>Library access</strong> for an account and ticks the libraries that person may see.',
            ],
            'figure' => [
                'picture' => 'administration-access',
                'alt' => 'The Library access dialog for one account: a list of libraries with a checkbox beside each, and buttons to cancel or save.',
                'caption' => 'Library access · Captured in Bånder with sample accounts',
            ],
        ],
        [
            'id' => 'settings',
            'heading' => 'Settings without a restart.',
            'text' => [
                'Server settings live in the database, and Bånder reads each one when it acts on it, so a change applies without a restart.',
                'Four features start switched off for you to turn on: audio transcoding, automatic MusicBrainz matching, automatic lyrics fetching and scheduled recommendations.',
            ],
            'figure' => [
                'picture' => 'libraries-enrichment',
                'alt' => 'The Content group of the server settings: three switches, for lyrics fetching, MusicBrainz matching and recommendations, all off.',
                'caption' => 'Server settings · Captured in Bånder',
            ],
            'layout' => 'wide',
        ],
    ],
    'guide' => [
        'needs' => 'A Bånder instance and an account with the administrator role; granting library access and changing settings need a super administrator. Bånder is pre-release and installs by building from source; the <a href="/docs/operator-guide/getting-started/installation/">installation guide</a> has the steps.',
        'steps' => [
            ['title' => 'Open the administration', 'text' => 'Open <strong>Settings</strong> and choose <strong>Admin</strong> under Administration.'],
            ['title' => 'Find a job', 'text' => 'Choose the <strong>Jobs</strong> tab, then select a job to open its details.'],
            ['title' => 'See what is scheduled', 'text' => "Choose the <strong>Scheduler</strong> tab and read each job's next run."],
            ['title' => 'Grant a library', 'text' => "As a super administrator, choose <strong>Security</strong>, open the menu on a user's row and choose <strong>Library access</strong>. Tick a library and choose <strong>Save</strong>."],
        ],
    ],
    'limits' => [
        "Transcodes have no record in the job monitor. They run in the worker deployment's media coordinator.",
        'Jobs and schedules run in the separate worker deployment. Without running workers, queued and scheduled work waits.',
        'Cancelling is cooperative: a job stops at its next checkpoint, and jobs without checkpoints run to the end.',
        'When a component such as Redis or the background worker turns unhealthy, each administrator gets a notification in the app and nothing else: no email, push or webhook. Watch the server from outside too.',
        'Only super administrators edit accounts, assign roles, grant library access and change server settings. Other administrators see the settings read-only.',
        'These role checks apply to the browser and the API. The <a href="/features/console/">console</a> acts with full authority for anyone who has shell access to the installation.',
    ],
    'manual' => [
        ['label' => 'Operator guide: monitoring', 'href' => '/docs/operator-guide/operations/'],
        ['label' => 'Operator guide: user management', 'href' => '/docs/operator-guide/administration/user-management/'],
        ['label' => 'Operator guide: server settings', 'href' => '/docs/operator-guide/configuration/server-settings/'],
    ],
];
