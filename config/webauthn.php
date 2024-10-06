<?php

return [
    'relying_party' => [
        'id'   => parse_url(config('app.url'), PHP_URL_HOST),
        'name' => config('app.name'),
        'icon' => env('WEBAUTHN_ICON'),
    ],
];