<?php

namespace App\Packages\JsonSchema\Eloquent;

use App\Packages\JsonSchema\ModelSchema;

trait HasJsonSchema
{
    public function getJsonSchema()
    {
        return app(ModelSchema::class)->buildSchemaFor($this);
    }
}