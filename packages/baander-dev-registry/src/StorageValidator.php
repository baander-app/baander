<?php

declare(strict_types=1);

namespace Baander\DevRegistry;

interface StorageValidator
{
    public function validate(string $root): void;
}
