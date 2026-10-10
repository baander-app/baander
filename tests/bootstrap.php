<?php

use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// PHPUnit sets APP_ENV=test via phpunit.xml <server> tag, but that only affects
// $_SERVER — Dotenv checks $_SERVER to determine which .env files to load.
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// The application's DBAL types, as the kernel registers them, for tests that use DBAL without it.
CustomTypesRegistrar::register();
