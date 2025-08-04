<?php

namespace Baander\RedisStack\Schema;

use Baander\RedisStack\Attributes\Boolean;
use Baander\RedisStack\Attributes\Date;
use Baander\RedisStack\Attributes\Id;
use Baander\RedisStack\Attributes\Number;
use Baander\RedisStack\Attributes\Text;
use ReflectionClass;

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
                    case Id::class:
                        $schema[$fieldName] = ['type' => 'string'];
                        break;

                    case Text::class:
                        $options = $attribute->newInstance();
                        $schema[$fieldName] = [
                            'type' => 'text',
                            'sortable' => $options->sortable
                        ];
                        break;

                    case Number::class:
                        $options = $attribute->newInstance();
                        $schema[$fieldName] = [
                            'type' => 'number',
                            'sortable' => $options->sortable
                        ];
                        break;

                    case Boolean::class:
                        $schema[$fieldName] = ['type' => 'boolean'];
                        break;

                    case Date::class:
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