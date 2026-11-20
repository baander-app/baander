<?php

use App\Kernel;
use Baander\Core\EnvironmentVariable;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context[EnvironmentVariable::APP_ENV], (bool) $context[EnvironmentVariable::APP_DEBUG]);
};
