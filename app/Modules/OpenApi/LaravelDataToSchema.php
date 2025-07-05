<?php

namespace App\Modules\OpenApi;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\BooleanType;
use Dedoc\Scramble\Support\Type\FloatType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class LaravelDataToSchema extends TypeToSchemaExtension
{
    /**
     * We establish that we handle here all the Spatie\LaravelData\Data classes.
     * This is because it is a pro feature of scramble and we do not have that kind of money.
     */
    public function shouldHandle(Type $type): bool
    {
        // Don't handle collections here - let LaravelDataCollectionExtension handle them
        if ($type instanceof Generic) {
            $isCollection = $type->name === DataCollection::class
                || $type->name === 'Illuminate\Support\Collection'
                || $type->name === AnonymousResourceCollection::class;

            if ($isCollection && isset($type->templateTypes[0])) {
                return false; // Let collection extension handle this
            }
        }

        return $type->isInstanceOf(Data::class);
    }

    /**
     * @param Type $type the type being transformed to schema
     */
    public function toSchema(Type $type): ?OpenApiType
    {
        /** @var ObjectType $type */
        $reflect = new \ReflectionClass($type->name);
        $props = $reflect->getProperties(\ReflectionProperty::IS_PUBLIC);

        $ret = new OpenApiObjectType();
        collect($props)->each(function ($prop) use ($ret): void {
            $to_convert_type = $this->convertReflected($prop->getType());
            $ret->addProperty($prop->name, $this->openApiTransformer->transform($to_convert_type));
        });

        return $ret;
    }

    /**
     * Set a reference to that object in the return.
     */
    public function reference(ObjectType $type): Reference
    {
        return new Reference('schemas', $type->name, $this->components);
    }

    /**
     * Given a pure reflected PHP type, we return the corresponding Scramble type equivalent before Generator conversion.
     *
     * @throws \InvalidArgumentException
     */
    private function convertReflected(\ReflectionNamedType|\ReflectionUnionType|\ReflectionType|null $type): Type
    {
        if ($type === null) {
            return new NullType();
        }

        if ($type instanceof \ReflectionUnionType) {
            return $this->handleUnionType($type);
        }

        if ($type instanceof \ReflectionIntersectionType) {
            throw new \RuntimeException('Intersection types are not supported.');
        }

        if (!$type instanceof \ReflectionNamedType) {
            throw new \RuntimeException('Unexpected reflection type.');
        }

        $name = $type->getName();
        if ($type->isBuiltin()) {
            return $this->handleBuiltin($name);
        }

        return match ($name) {
            'Spatie\LaravelData\Data' => throw new \RuntimeException('Spatie\LaravelData\Data should not be used as return type.'),
            'Illuminate\Support\Collection' => new Generic('Illuminate\Support\Collection', [new ObjectType('mixed')]),
            'Spatie\LaravelData\DataCollection' => new Generic('Spatie\LaravelData\DataCollection', [new ObjectType('mixed')]),
            AnonymousResourceCollection::class => new Generic(AnonymousResourceCollection::class, [new ObjectType('mixed')]),
            default => new ObjectType($name),
        };
    }

    private function handleUnionType(\ReflectionUnionType $union): Type
    {
        $types = collect($union->getTypes())->map(fn ($type) => $this->convertReflected($type))->all();
        return new Union($types);
    }

    private function handleBuiltin(string $type): Type
    {
        return match ($type) {
            'null' => new NullType(),
            'int' => new IntegerType(),
            'float' => new FloatType(),
            'bool' => new BooleanType(),
            'array' => new ArrayType(),
            'string' => new StringType(),
            default => throw new \RuntimeException('Unknown type: ' . $type),
        };
    }
}