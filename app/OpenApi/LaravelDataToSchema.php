<?php

namespace App\OpenApi;

use App\OpenApi\Concerns\CastsDtoToSchema;
use Exception;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Illuminate\Http\Response;
use Dedoc\Scramble\Support\Type\{ObjectType, Type};
use Spatie\LaravelData\Data;

class LaravelDataToSchema extends TypeToSchemaExtension
{
    use CastsDtoToSchema;

    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && $type->isInstanceOf(Data::class);
    }

    /**
     * @return mixed
     * @throws Exception
     */
    public function toSchema(Type $type)
    {
        if ($type instanceof ObjectType) {
            return $this->schemaFromDto($type->name);
        }

        return parent::toSchema($type);
    }

    /**
     * @param Generic $type
     * @throws \ReflectionException
     */
    public function toResponse(Type $type)
    {
        return Response::make(200)
            ->setContent(
                'application/json',
                $this->schemaFromDto($type->name),
            );
    }
}