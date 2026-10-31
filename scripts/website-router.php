<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

return Baander\Tooling\Website\Preview::route(dirname(__DIR__) . '/var/website', $_SERVER['REQUEST_URI'] ?? '/');
