<?php

declare(strict_types=1);

namespace App\Scheduler\Domain\Model;

use OpenApi\Spec\Property;
use OpenApi\Undefined;

/**
 * Builds scheduler parameter schemas from native OpenAPI property attributes.
 *
 * Reads each property's native schema and produces the
 * parameter schema array expected by SchedulableCommandInterface::schedulerParameters().
 *
 * Usage:
 *   class MyCommand implements SchedulableCommandInterface {
 *       use SchedulerParameterSchema;
 *       public static function schedulerParameters(): array {
 *           return self::buildSchemaFromOaClass(MySchedulerParams::class);
 *       }
 *   }
 */
trait SchedulerParameterSchema
{
    /**
     * @param class-string $class The parameter class
     * @return array<string, array{type: string, required: bool, description?: string, default?: mixed, format?: string, example?: mixed, enum?: array<string>, nullable?: bool}>
     */
    protected static function buildSchemaFromOaClass(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $schema = [];

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Property::class);
            if ($attributes === []) {
                continue;
            }

            $propertySchema = $attributes[0]->newInstance()->schema;
            if ($propertySchema === null) {
                continue;
            }
            $name = $property->getName();

            $entry = [
                'type' => $propertySchema->type ?? 'string',
                'required' => !$property->hasDefaultValue() && $propertySchema->nullable !== true,
                'description' => !Undefined::isDefault($propertySchema->description) ? ($propertySchema->description ?? '') : '',
            ];

            if ($property->hasDefaultValue()) {
                $entry['default'] = $property->getDefaultValue();
            }

            if ($propertySchema->nullable === true) {
                $entry['nullable'] = true;
            }

            if ($propertySchema->format !== null && $propertySchema->format !== '') {
                $entry['format'] = $propertySchema->format;
            }

            if (!Undefined::isDefault($propertySchema->example)) {
                $entry['example'] = $propertySchema->example;
            }

            if ($propertySchema->enum !== null && $propertySchema->enum !== []) {
                $entry['enum'] = $propertySchema->enum;
            }

            $schema[$name] = $entry;
        }

        return $schema;
    }
}
