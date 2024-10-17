<?php

namespace App\OpenApi\Concerns;

use Closure;
use Dedoc\Scramble\Support\Generator\{Parameter, Schema};
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\RulesToParameters;
use Illuminate\Validation\NestedRules;
use Laravel\SerializableClosure\Support\ReflectionClosure;
use ReflectionClass;
use ReflectionException;
use ReflectionObject;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\DataProperty;

trait CastsDtoToSchema
{
    public function schemaFromDto(string $className): Schema
    {
        if (!class_exists($className) || !is_a($className, Data::class, true)) {
            throw new \Exception("$className is not a valid Data object");
        }

        return Schema::createFromParameters($this->parametersFromDto($className));
    }

    /**
     * @return Parameter[]
     *
     * @throws \ReflectionException
     */
    public function parametersFromDto(string $className): array
    {
        return (new RulesToParameters($this->rulesFromDto($className), [], $this->openApiTransformer))->handle();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \ReflectionException
     */
    public function rulesFromDto(string $className): array
    {
        return $this->unwrapRules($this->rulesFromDtoNoRecursion($className));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \ReflectionException
     */
    public function rulesFromDtoNoRecursion(string $className): array
    {
        if (!class_exists($className) || !is_a($className, Data::class, true)) {
            throw new \Exception("$className is not a valid Data object");
        }

        $dataObject = (new ReflectionClass($className))->newInstanceWithoutConstructor();

        return $dataObject->getValidationRules([]);
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     *
     * @throws ReflectionException
     */
    protected function unwrapRules(array $rules): array
    {
        foreach ($rules as $key => $rule) {
            if (!$rule instanceof NestedRules) {
                continue;
            }

            unset($rules[$key]);

            return $this->unwrapRules([
                ...$rules,
                ...$this->expandRules($rule, $key),
            ]);
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \ReflectionException
     */
    protected function expandRules(NestedRules $rule, string $parentKey = ''): array
    {
        $closure = (new ReflectionObject($rule))->getProperty('callback')->getValue($rule);

        if (!$closure instanceof Closure) {
            return [];
        }

        $useVariables = (new ReflectionClosure($closure))->getUseVariables();

        if (!(($prop = ($useVariables['dataProperty'] ?? null)) instanceof DataProperty)) {
            return [];
        }

        $attribute = $prop->attributes
            ->filter(fn($attribute) => is_a($attribute->class, DataCollectionOf::class, true))
            ->first();

        $rules = $this->rulesFromDtoNoRecursion($attribute->class);

        foreach ($rules as $key => $rule) {
            $rules["$parentKey.$key"] = $rule;
            unset($rules[$key]);
        }

        return $rules;
    }
}