<?php

declare(strict_types=1);

// PHPStan Doctrine extension object manager loader
// Returns the EntityManager for Doctrine metadata resolution

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$kernel = new App\Kernel('test', false);
$kernel->boot();

/** @var EntityManagerInterface $entityManager */
$entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');

return $entityManager;
