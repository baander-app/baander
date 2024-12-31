<?php

namespace App\Filters\Contracts;

interface Filterable
{
    public function handle($value): void;
}