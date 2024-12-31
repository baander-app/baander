<?php

namespace App\Modules\JsonSchema\Eloquent;

interface JsonSchemaRepresentable
{
    public function getJsonSchemaFieldOptions(): array;
}