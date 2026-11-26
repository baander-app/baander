<?php

declare(strict_types=1);

namespace Baander\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use SplObjectStorage;

/**
 * Mirrors the nested-call branch of PhpStorm's
 * com.jetbrains.php.lang.inspections.PhpPipeOperatorCanBeUsedInspection, as compiled in
 * PhpStorm 2026.3.0.1 (build 263.7307.352).
 *
 * A walk starts at a function call, variable callables included, whose arguments are not empty
 * and hold no call that takes arguments. Its depth starts at 2 when the only argument is an
 * argument-less call, otherwise at 1. It climbs while the current call is an argument of a
 * function, method or static call. An argument list that holds another call, or one owned by
 * `new` or an attribute, ends the walk without a report; a walk that leaves the argument lists
 * reports the outermost call when the depth is 3 to 10.
 *
 * Neither a first-class callable nor a pipe is a call: neither starts a walk, counts as a level
 * or counts as a call argument, and the walk above a pipe argument keeps its depth. The
 * inspection's closure check never matches, because PhpStorm's tree wraps a closure argument, so
 * closure arguments stop nothing.
 *
 * PHPStan gives call nodes no parent, so the rule takes the whole file and maps each argument
 * value to the node that owns its argument list. The map is its own because PHPStan shares the
 * nodes with every other rule.
 *
 * @implements Rule<FileNode>
 */
final readonly class NestedCallPipeOperatorRule implements Rule
{
    private const string MESSAGE = 'Nested function calls can be replaced with a pipe operator';
    private const string IDENTIFIER = 'baander.pipeOperator.nestedCalls';
    private const int MIN_DEPTH = 3;
    private const int MAX_DEPTH = 10;

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        /** @var list<CallLike|Attribute> $argumentOwners */
        $argumentOwners = new NodeFinder()->find($node->getNodes(), self::ownsArguments(...));
        $ownerOf = self::ownerMap($argumentOwners);

        $errors = [];
        foreach ($argumentOwners as $candidate) {
            $outermost = $candidate instanceof FuncCall ? self::reportedCall($candidate, $ownerOf) : null;
            if ($outermost !== null) {
                $errors[] = self::error($outermost);
            }
        }

        return $errors;
    }

    /** @param SplObjectStorage<Expr, CallLike|Attribute> $ownerOf */
    private static function reportedCall(FuncCall $start, SplObjectStorage $ownerOf): ?Expr
    {
        if (!self::startsWalk($start)) {
            return null;
        }

        $current = $start;
        $depth = self::startDepth($start);
        while ($depth <= self::MAX_DEPTH) {
            if (!$ownerOf->offsetExists($current)) {
                return $depth >= self::MIN_DEPTH ? $current : null;
            }

            $owner = $ownerOf[$current];
            if (!PipeOperatorCalls::isCall($owner) || self::callArgumentCount($owner) > 1) {
                return null;
            }

            $current = $owner;
            ++$depth;
        }

        return null;
    }

    private static function startsWalk(FuncCall $call): bool
    {
        $arguments = PipeOperatorCalls::arguments($call);

        return $arguments !== [] && !array_any($arguments, self::isCallWithArguments(...));
    }

    private static function isCallWithArguments(Expr $argument): bool
    {
        return PipeOperatorCalls::isCall($argument) && PipeOperatorCalls::arguments($argument) !== [];
    }

    private static function startDepth(FuncCall $call): int
    {
        $arguments = PipeOperatorCalls::arguments($call);

        return count($arguments) === 1 && PipeOperatorCalls::isCall($arguments[0]) ? 2 : 1;
    }

    private static function callArgumentCount(CallLike $call): int
    {
        return array_filter(PipeOperatorCalls::arguments($call), PipeOperatorCalls::isCall(...)) |> count(...);
    }

    private static function ownsArguments(Node $node): bool
    {
        return $node instanceof CallLike || $node instanceof Attribute;
    }

    /**
     * @param list<CallLike|Attribute> $owners
     * @return SplObjectStorage<Expr, CallLike|Attribute>
     */
    private static function ownerMap(array $owners): SplObjectStorage
    {
        /** @var SplObjectStorage<Expr, CallLike|Attribute> $ownerOf */
        $ownerOf = new SplObjectStorage();
        foreach ($owners as $owner) {
            foreach (PipeOperatorCalls::arguments($owner) as $value) {
                $ownerOf[$value] = $owner;
            }
        }

        return $ownerOf;
    }

    private static function error(Expr $call): IdentifierRuleError
    {
        return RuleErrorBuilder::message(self::MESSAGE)
            ->identifier(self::IDENTIFIER)
            ->line($call->getStartLine())
            ->tip(PipeOperatorCalls::TIP)
            ->build();
    }
}
