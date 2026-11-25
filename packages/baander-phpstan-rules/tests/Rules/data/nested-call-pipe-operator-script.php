<?php

declare(strict_types=1);

$name = $argv[1] ?? '';

echo strtoupper(trim(strtolower($name))), "\n";
