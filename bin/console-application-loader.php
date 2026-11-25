<?php

declare(strict_types=1);

// PHPStan Symfony extension console application loader
// Returns the Console Application for Symfony service container analysis

use App\Kernel;
use Baander\Core\EnvironmentVariable;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__ . '/../.env');

// Analysis only builds the container, but services that create their storage directory when they
// are built fail on a host checkout, where the .env path names a directory inside the container.
// Such a path is replaced by one under the system temp directory; it is set after bootEnv, which
// would otherwise restore the .env value.
$conversions = (string) ($_SERVER[EnvironmentVariable::CONVERT_STORAGE_PATH] ?? '');
if (!is_dir($conversions) && !@mkdir($conversions, 0775, true) && !is_dir($conversions)) {
    $_SERVER[EnvironmentVariable::CONVERT_STORAGE_PATH] = $_ENV[EnvironmentVariable::CONVERT_STORAGE_PATH] = sys_get_temp_dir() . '/baander-phpstan/conversions';
}

$kernel = new Kernel('dev', true);

return new Application($kernel);
