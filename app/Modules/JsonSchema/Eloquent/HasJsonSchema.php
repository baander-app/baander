<?php

namespace App\Modules\JsonSchema\Eloquent;

use App\Modules\JsonSchema\ModelSchema;

trait HasJsonSchema
{
    public function getJsonSchema()
    {
        return app(ModelSchema::class)->buildSchemaFor($this);
    }
}