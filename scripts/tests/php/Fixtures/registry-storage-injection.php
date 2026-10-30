<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (class_exists('Baander\\RegistryTests\\Runtime', false)) {
    exit(99);
}

$validator = new class implements Baander\Tooling\Registry\StorageValidator {
    public function validate(string $root): void
    {
        if ($root !== $_SERVER['argv'][1]) {
            throw new RuntimeException('Wrong storage root.', 98);
        }
        throw new RuntimeException('Explicit storage validation reached without test support.', 31);
    }
};

exit(new Baander\Tooling\Registry\QualificationRunner($_SERVER['argv'][1], $validator)->run(['release']));
