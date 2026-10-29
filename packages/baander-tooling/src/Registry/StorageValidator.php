<?php

declare(strict_types=1);

namespace Baander\Tooling\Registry;

interface StorageValidator
{
    public function validate(string $root): void;
}
