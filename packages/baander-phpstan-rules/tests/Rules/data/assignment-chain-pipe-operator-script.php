<?php

declare(strict_types=1);

$name = $argv[1] ?? '';
$name = trim($name);
$name = strtolower($name);

echo $name, "\n";
