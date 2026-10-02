<?php

declare(strict_types=1);

namespace Baander\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<ClassMethod>
 */
final class MapRequestPayloadObjectTypeRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($node->getParams() as $param) {
            if (!$this->hasMapRequestPayloadAttribute($param, $scope)) {
                continue;
            }

            $type = $param->type;

            if ($type === null) {
                $errors[] = RuleErrorBuilder::message(
                    'Parameter $' . $this->getParamName($param) . ' with #[MapRequestPayload] must have an explicit type hint (not bare "object").',
                )
                    ->identifier('baander.mapRequestPayload.objectType')
                    ->build();
                continue;
            }

            if ($type instanceof Node\NullableType) {
                $type = $type->type;
            }

            $types = $type instanceof Node\UnionType ? $type->types : [$type];
            foreach ($types as $member) {
                if ($member instanceof Node\Identifier && strtolower($member->toString()) === 'object') {
                    $errors[] = RuleErrorBuilder::message(
                        'Parameter $' . $this->getParamName($param) . ' with #[MapRequestPayload] is typed as "object". Use a concrete request DTO class instead.',
                    )
                        ->identifier('baander.mapRequestPayload.objectType')
                        ->build();
                    break;
                }
            }
        }

        return $errors;
    }

    private function hasMapRequestPayloadAttribute(Param $param, Scope $scope): bool
    {
        foreach ($param->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if (strcasecmp($scope->resolveName($attr->name), 'Symfony\Component\HttpKernel\Attribute\MapRequestPayload') === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getParamName(Param $param): string
    {
        if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
            return $param->var->name;
        }

        return 'unknown';
    }
}
