<?php

declare(strict_types=1);

namespace App\Scheduler\Domain\Model;

use OpenApi\Attributes\Property;
use OpenApi\Generator;

/**
 * Trait for building scheduler parameter schemas from OA-annotated classes.
 *
 * Reads #[OA\Property] attributes from a given class and produces the
 * parameter schema array expected by SchedulableCommandInterface::schedulerParameters().
 *
 * Uses Generator::isDefault() to guard against OpenApi's sentinel UNDEFINED
 * strings that leak into responses when compared with naive null/empty checks.
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
     * @param class-string $class The OA-annotated parameter class
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

            $oaProperty = $attributes[0]->newInstance();
            $name = $property->getName();

            $entry = [
                'type' => !Generator::isDefault($oaProperty->type) ? $oaProperty->type : 'string',
                'required' => !$property->hasDefaultValue() && (Generator::isDefault($oaProperty->nullable) || !$oaProperty->nullable),
                'description' => !Generator::isDefault($oaProperty->description) ? $oaProperty->description : '',
            ];

            if ($property->hasDefaultValue()) {
                $entry['default'] = $property->getDefaultValue();
            }

            if (!Generator::isDefault($oaProperty->nullable) && $oaProperty->nullable) {
                $entry['nullable'] = true;
            }

            if (!Generator::isDefault($oaProperty->format) && $oaProperty->format !== '') {
                $entry['format'] = $oaProperty->format;
            }

            if (!Generator::isDefault($oaProperty->example)) {
                $entry['example'] = $oaProperty->example;
            }

            if (!Generator::isDefault($oaProperty->enum) && $oaProperty->enum !== []) {
                $entry['enum'] = $oaProperty->enum;
            }

            $schema[$name] = $entry;
        }

        return $schema;
    }
}
