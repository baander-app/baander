<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
     * Your API path. By default, all routes starting with this path will be added to the docs.
     * If you need to change this behavior, you can add your custom routes resolver using `Scramble::routes()`.
     */
    'api_path'    => '',

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain'  => null,

    /*
     * The path where your OpenAPI specification will be exported.
     */
    'export_path' => 'api.json',

    'info'    => [
        /*
         * API version.
         */
        'version'     => env('API_VERSION', '0.0.1'),

        /*
         * Description rendered on the home page of the API documentation (`/docs/api`).
         */
        'description' => <<<DESCRIPTION
Bånder.App is a sophisticated media server developed with the primary goal to deliver high performance and seamless experience for users.
Being built on top of Laravel framework and PostgreSQL for database, it leverages the power of robust backend technologies, providing high security, reliability, and scalability.

The main focus of Bånder.App is efficient media management and delivery.
This application is designed to handle large amounts of media files while ensuring quick and efficient access.
Implemented queuing functionality through Redis offers efficient job management and load handling.
The media information is organized and easily searchable, ensuring users can always find what they are looking for quickly and easily.
DESCRIPTION
,
    ],

    /*
     * Customize Stoplight Elements UI
     */
    'ui'      => [
        /*
         * Define the title of the documentation's website. App name is used when this config is `null`.
         */
        'title'                     => 'Bånder api docs',

        /*
         * Define the theme of the documentation. Available options are `light` and `dark`.
         */
        'theme'                     => 'light',

        /*
         * Hide the `Try It` feature. Enabled by default.
         */
        'hide_try_it'               => false,

        /*
         * URL to an image that displays as a small square logo next to the title, above the table of contents.
         */
        'logo'                      => 'https://baander.test/baander-logo.svg',

        /*
         * Use to fetch the credential policy for the Try It feature. Options are: omit, include (default), and same-origin
         */
        'try_it_credentials_policy' => 'include',
    ],

    /*
     * The list of servers of the API. By default, when `null`, server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     *
     * Example of non-default config (final URLs are generated using Laravel `url` helper):
     *
     * ```php
     * 'servers' => [
     *     'Live' => 'api',
     *     'Prod' => 'https://scramble.dedoc.co/api',
     * ],
     * ```
     */
    'servers' => null,

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [
        \App\Modules\Http\OpenApi\JsonPaginatorExtension::class,
        \App\Modules\Http\OpenApi\LaravelDataRequestExtension::class,
        \App\Modules\Http\OpenApi\LaravelDataToSchema::class,
        \App\Modules\Http\OpenApi\LaravelDataCollectionExtension::class,
    ],
];
