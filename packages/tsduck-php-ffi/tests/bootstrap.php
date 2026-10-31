<?php

declare(strict_types=1);

use Tsduck\FFI\LibTSDuck;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Install the TSDuck package development dependencies before running its qualification suite.');
}

require $autoload;

foreach (['ffi', 'mbstring'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException(sprintf('TSDuck qualification requires ext-%s.', $extension));
    }
}

// Loading the complete binding also verifies that FFI is enabled and every declared
// native symbol resolves. Missing or incompatible libraries must fail qualification.
LibTSDuck::getInstance();
