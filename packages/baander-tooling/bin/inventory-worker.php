#!/usr/bin/env php
<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'];
array_shift($arguments);
if (array_shift($arguments) !== '--composer-loader' || ($loader = array_shift($arguments)) === null || !is_file($loader)) {
    fwrite(STDERR, "Inventory worker requires --composer-loader PATH.\n");
    exit(2);
}
require $loader;
exit(\Baander\Tooling\Inventory\Commands::main([__FILE__, ...$arguments], $loader));
