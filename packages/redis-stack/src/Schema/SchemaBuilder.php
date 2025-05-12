<?php

namespace Baander\RedisStack\Schema;

use ReflectionClass;
use ReflectionProperty;

class SchemaBuilder
{
    public static function build(object $entity): array
    {
        $reflection = new ReflectionClass($entity);
        $properties = $reflection->getProperties();

        $schema = [];
        foreach ($properties as $property) {
            foreach ($property->getAttributes() as $attribute) {
                $attributeName = $attribute->getName();
                $fieldName = $property->getName();

                // Map attributes to schema fields
                switch ($attributeName) {
                    case \Baander\RedisStack\Attributes\Id::class:
                        $schema[$fieldName] = ['type' => 'string'];
                        break;

                    case \Baander\RedisStack\Attributes\Text::class:
                        $options = $attribute->newInstance();
                        $schema[$fieldName] = [
                            'type' => 'text',
                            'sortable' => $options->sortable
                        ];
                        break;

                    case \Baander\RedisStack\Attributes\Number::class:
                        $options = $attribute->newInstance();
                        $schema[$fieldName] = [
                            'type' => 'number',
                            'sortable' => $options->sortable
                        ];
                        break;

                    case \Baander\RedisStack\Attributes\Boolean::class:
                        $schema[$fieldName] = ['type' => 'boolean'];
                        break;

                    case \Baander\RedisStack\Attributes\Date::class:
                        $options = $attribute->newInstance();
                        $schema[$fieldName] = [
                            'type' => 'date',
                            'sortable' => $options->sortable
                        ];
                        break;
                }
            }
        }

        return $schema;
    }
}