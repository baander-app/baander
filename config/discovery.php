<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Discovery Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable the local network discovery service. When enabled,
    | the server will respond to UDP broadcast discovery requests from
    | Electron clients on the local network.
    |
    */

    'enabled' => env('DISCOVERY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Discovery Port
    |--------------------------------------------------------------------------
    |
    | The UDP port that the discovery service will listen on. Clients will
    | send broadcast messages to this port to discover servers on the
    | local network.
    |
    | Default: 41234
    |
    */

    'port' => env('DISCOVERY_PORT', 41234),

    /*
    |--------------------------------------------------------------------------
    | Server Name
    |--------------------------------------------------------------------------
    |
    | The name that will be broadcast in discovery responses. Defaults to
    | the application name from config/app.php. You can override this
    | with an environment variable if needed.
    |
    */

    'server_name' => env('DISCOVERY_SERVER_NAME', config('app.name')),

    /*
    |--------------------------------------------------------------------------
    | Discovery Interval
    |--------------------------------------------------------------------------
    |
    | How often (in seconds) the server should clean up stale discovery
    | responses. This is primarily for maintaining the internal state
    | of the discovery service.
    |
    */

    'interval' => env('DISCOVERY_INTERVAL', 60),
];