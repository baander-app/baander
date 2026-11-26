<?php

declare(strict_types=1);

namespace Baander\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;

/**
 * What PhpStorm's pipe-operator inspection counts as a call and as its arguments, shared by the
 * nested-call and the assignment-chain rule so the two branches cannot drift apart.
 */
final class PipeOperatorCalls
{
    public const string TIP = 'See docs-book/part-2-developer-guide/development/coding-conventions.md#pipe-operator for the house fix.';

    /**
     * A first-class callable is not a call: PhpStorm's parameter list sees no arguments in it.
     *
     * @phpstan-assert-if-true FuncCall|MethodCall|NullsafeMethodCall|StaticCall $node
     */
    public static function isCall(Node $node): bool
    {
        return ($node instanceof FuncCall
            || $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof StaticCall)
            && !$node->isFirstClassCallable();
    }

    /** @return list<Expr> */
    public static function arguments(CallLike|Attribute $owner): array
    {
        $values = [];
        foreach ($owner instanceof CallLike ? $owner->getRawArgs() : $owner->args as $argument) {
            if ($argument instanceof Arg) {
                $values[] = $argument->value;
            }
        }

        return $values;
    }
}
