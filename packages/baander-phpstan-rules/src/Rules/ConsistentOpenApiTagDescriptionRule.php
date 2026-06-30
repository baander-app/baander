<?php

declare(strict_types=1);

namespace Baander\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Class_>
 */
final class ConsistentOpenApiTagDescriptionRule implements Rule
{
    /** @var array<string, array{description: string|null, file: string, line: int}> */
    private static array $tags = [];

    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->namespacedName === null) {
            return [];
        }

        $errors = [];

        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $attr->name->toString();
                if ($name !== 'OpenApi\Attributes\Tag' && $name !== 'OA\Tag') {
                    continue;
                }

                $tagName = $this->getNamedArgument($attr, 'name');
                if ($tagName === null) {
                    continue;
                }

                $description = $this->getNamedArgument($attr, 'description');

                if (!isset(self::$tags[$tagName])) {
                    self::$tags[$tagName] = [
                        'description' => $description,
                        'file' => $scope->getFile(),
                        'line' => $attr->getStartLine(),
                    ];
                    continue;
                }

                $existing = self::$tags[$tagName];
                if ($existing['description'] !== $description) {
                    $errors[] = RuleErrorBuilder::message(\sprintf(
                        'OA\Tag(name: %s) has conflicting descriptions.%s  Current: %s%s  Previously defined in %s:%d as: %s',
                        $tagName,
                        "\n",
                        $description ?? '(none)',
                        "\n",
                        basename($existing['file']),
                        $existing['line'],
                        $existing['description'] ?? '(none)',
                    ))
                        ->identifier('baander.openapiTag.conflictingDescription')
                        ->build();
                }
            }
        }

        return $errors;
    }

    private function getNamedArgument(Node\Attribute $attr, string $argumentName): ?string
    {
        foreach ($attr->args as $arg) {
            if ($arg->name === null || $arg->name->toString() !== $argumentName) {
                continue;
            }

            if ($arg->value instanceof Node\Scalar\String_) {
                return $arg->value->value;
            }
        }

        return null;
    }
}
