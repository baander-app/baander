<?php

return [
    /*
     *  Automatic registration of routes will only happen if this setting is `true`
     */
    'enabled'     => true,

    /*
     * Controllers in these directories that have routing attributes
     * will automatically be registered.
     *
     * Optionally, you can specify group configuration by using key/values
     */
    'directories' => [
        app_path('Http/Controllers/Api') => [
            'prefix'     => 'api',
            'middleware' => 'api',
            'patterns'   => ['*Controller.php'],
        ],
//        app_path('Http/Controllers/Web') => [
//            'middleware' => 'web',
//            'patterns'   => ['*Controller.php'],
//        ],
    ],

    /**
     * This middleware will be applied to all routes.
     */
    'middleware'  => [
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ],
];
