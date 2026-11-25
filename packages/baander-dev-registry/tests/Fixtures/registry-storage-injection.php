<?php

declare(strict_types=1);
use Baander\DevRegistry\QualificationRunner;
use Baander\DevRegistry\StorageValidator;

require dirname(__DIR__, 4) . '/scripts/vendor/autoload.php';

if (class_exists('Baander\\RegistryTests\\Runtime', false)) {
    exit(99);
}

$validator = new class implements StorageValidator {
    public function validate(string $root): void
    {
        if ($root !== $_SERVER['argv'][1]) {
            throw new RuntimeException('Wrong storage root.', 98);
        }
        throw new RuntimeException('Explicit storage validation reached without test support.', 31);
    }
};

exit(new QualificationRunner($_SERVER['argv'][1], $validator)->run(['release']));
