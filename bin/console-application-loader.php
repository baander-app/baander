<?php

declare(strict_types=1);

// PHPStan Symfony extension console application loader
// Returns the Console Application for Symfony service container analysis

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$kernel = new App\Kernel('dev', true);

return new Application($kernel);
