<?php

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return fn () => new App\Kernel($_SERVER['APP_ENV'] ?? 'prod', (bool) ($_SERVER['APP_DEBUG'] ?? false));
