<?php

namespace App\Modules\OpenApi;

use App\Modules\OpenApi\Concerns\CastsDtoToSchema;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class LaravelDataCollectionExtension extends TypeToSchemaExtension
{
    use CastsDtoToSchema;

    public function shouldHandle(Type $type): bool
    {
        if($type instanceof Generic && count($type->templateTypes) >= 1) {
            // Handle DataCollection<Data> directly (not Generic)
            if ($type->isInstanceOf(DataCollection::class)) {
                return true;
            }

            // Handle DataCollection<Data>
            if ($type->name === DataCollection::class) {
                return $type->templateTypes[0] instanceof ObjectType
                    && $type->templateTypes[0]->isInstanceOf(Data::class);
            }

            // Handle Collection<Data>
            if ($type->name === 'Illuminate\Support\Collection') {
                return $type->templateTypes[0] instanceof ObjectType
                    && $type->templateTypes[0]->isInstanceOf(Data::class);
            }

            // Handle AnonymousResourceCollection
            if ($type->name === AnonymousResourceCollection::class) {
                return $type->templateTypes[0] instanceof ObjectType
                    && $type->templateTypes[0]->isInstanceOf(Data::class);
            }
        }



        return false;
    }

    public function toSchema(Type $type)
    {
        $collectingClassType = null;

        // Handle direct DataCollection types
        if ($type instanceof ObjectType && $type->isInstanceOf(DataCollection::class)) {
            // For non-generic DataCollection, create a simple array response
            $arrayType = new ArrayType();

            return $this->openApiTransformer->transform($arrayType);
        }

        // Handle Generic types
        if ($type instanceof Generic) {
            $collectingClassType = $type->templateTypes[0] ?? null;
        }

        if (!($collectingClassType instanceof ObjectType) || !$collectingClassType->isInstanceOf(Data::class)) {
            return null;
        }

        $type = new ArrayType(value: $type->templateTypes[1]);

        return $this->openApiTransformer->transform($type);
    }
}