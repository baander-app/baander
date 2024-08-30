<?php

namespace App\Services\Widgets\Builders;

use JetBrains\PhpStorm\ArrayShape;

interface BuilderInterface
{
    #[ArrayShape([
        'user' => 'mixed'
    ])]
    public function __construct(array $context);

    public function build(): array;
}