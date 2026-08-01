<?php

use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// PHPUnit sets APP_ENV=test via phpunit.xml <server> tag, but that only affects
// $_SERVER — Dotenv checks $_SERVER to determine which .env files to load.
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Register citext as a string type for PostgreSQL
if (!Type::hasType('citext')) {
    Type::addType('citext', StringType::class);
}
