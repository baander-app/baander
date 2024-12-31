<?php

namespace App\Packages\JsonSchema\Eloquent;

interface JsonSchemaRepresentable
{
    public function getJsonSchemaFieldOptions(): array;
}