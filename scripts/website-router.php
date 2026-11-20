<?php

declare(strict_types=1);

use Baander\DevWebsite\Preview;

require __DIR__ . '/bootstrap.php';

return Preview::route(dirname(__DIR__) . '/var/website', $_SERVER['REQUEST_URI'] ?? '/');
