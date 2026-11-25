<?php

declare(strict_types=1);

namespace Baander\PHPStan\Rules;

use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Mirrors the assignment-chain branch of PhpStorm's
 * com.jetbrains.php.lang.inspections.PhpPipeOperatorCanBeUsedInspection, as compiled in
 * PhpStorm 2026.3.0.1 (build 263.7307.352).
 *
 * A chain starts at a plain `=` statement unless the previous statement assigns the same
 * variable. When its value is a call, no argument may be a call or the variable itself. Each
 * following statement that assigns the same variable must assign a call that takes the variable
 * as a direct argument and no call argument; any other assignment to the variable abandons the
 * chain. The first other statement ends it, and a chain of 3 to 10 assignments is reported once,
 * at its first statement; PhpStorm marks every assignment.
 *
 * A first-class callable is not a call, neither as a value nor as an argument. The inspection's
 * closure check never matches, because PhpStorm's tree wraps a closure value, so a closure may
 * open a chain. Line and block comments are leaves in PhpStorm's tree and are skipped. A docblock
 * is an element of its own, so it ends a chain and lets the statement after it start one.
 * Variables match when they print the same.
 *
 * @implements Rule<FileNode>
 */
final readonly class AssignmentChainPipeOperatorRule implements Rule
{
    private const string MESSAGE = 'Chain of assignments can be replaced with a pipe operator';
    private const string IDENTIFIER = 'baander.pipeOperator.assignmentChain';
    private const string TIP = 'See docs-book/part-2-developer-guide/development/coding-conventions.md#pipe-operator for the house fix.';
    private const int MIN_LENGTH = 3;
    private const int MAX_LENGTH = 10;

    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach (self::statementLists($node->getNodes()) as $statements) {
            foreach (array_keys($statements) as $index) {
                $length = $this->chainLength($statements, $index);
                if ($length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH) {
                    $errors[] = self::error($statements[$index]);
                }
            }
        }

        return $errors;
    }

    /**
     * The number of assignments in the chain that starts at the given statement: 0 when none
     * starts there or the chain is abandoned, more than the maximum when it runs past it.
     *
     * @param list<Stmt> $statements
     */
    private function chainLength(array $statements, int $start): int
    {
        $first = self::assignmentIn($statements[$start]);
        if (!$first instanceof Assign) {
            return 0;
        }

        $variable = $this->variableKey($first);
        if ($variable === null
            || $this->followsAssignmentTo($statements, $start, $variable)
            || !$this->opensChain($first->expr, $variable)) {
            return 0;
        }

        $length = 1;
        while ($length <= self::MAX_LENGTH) {
            $next = $statements[$start + $length] ?? null;
            $assignment = $next === null || self::hasDocComment($next) ? null : self::assignmentIn($next);
            if ($assignment === null || $this->variableKey($assignment) !== $variable) {
                return $length;
            }

            if (!$this->continuesChain($assignment->expr, $variable)) {
                return 0;
            }

            ++$length;
        }

        return $length;
    }

    /**
     * A statement that carries a docblock has the docblock, not the previous statement, before it.
     *
     * @param list<Stmt> $statements
     */
    private function followsAssignmentTo(array $statements, int $start, string $variable): bool
    {
        $previous = $statements[$start - 1] ?? null;
        if ($previous === null || self::hasDocComment($statements[$start])) {
            return false;
        }

        $assignment = self::assignmentIn($previous);

        return $assignment !== null && $this->variableKey($assignment) === $variable;
    }

    private function opensChain(Expr $value, string $variable): bool
    {
        return !self::isCall($value) || !array_any(
            self::arguments($value),
            fn (Expr $argument): bool => self::isCall($argument) || $this->key($argument) === $variable,
        );
    }

    private function continuesChain(Expr $value, string $variable): bool
    {
        if (!self::isCall($value)) {
            return false;
        }

        $arguments = self::arguments($value);

        return !array_any($arguments, self::isCall(...))
            && array_any($arguments, fn (Expr $argument): bool => $this->key($argument) === $variable);
    }

    /** Compound and by-reference assignments count as assigning the variable. */
    private static function assignmentIn(Stmt $statement): Assign|AssignOp|AssignRef|null
    {
        $expression = $statement instanceof Expression ? $statement->expr : null;

        return $expression instanceof Assign || $expression instanceof AssignOp || $expression instanceof AssignRef
            ? $expression
            : null;
    }

    private function variableKey(Assign|AssignOp|AssignRef $assignment): ?string
    {
        $variable = $assignment->var;

        return $variable instanceof List_ || $variable instanceof Array_ ? null : $this->key($variable);
    }

    private function key(Expr $expression): string
    {
        return $this->printer->prettyPrintExpr($expression);
    }

    private static function hasDocComment(Stmt $statement): bool
    {
        return array_any($statement->getComments(), static fn (Comment $comment): bool => $comment instanceof Doc);
    }

    /** @phpstan-assert-if-true FuncCall|MethodCall|NullsafeMethodCall|StaticCall $node */
    private static function isCall(Node $node): bool
    {
        return ($node instanceof FuncCall
            || $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof StaticCall)
            && !$node->isFirstClassCallable();
    }

    /**
     * The argument values, as PhpStorm's parameter list sees them: a first-class callable has none.
     *
     * @return list<Expr>
     */
    private static function arguments(FuncCall|MethodCall|NullsafeMethodCall|StaticCall $call): array
    {
        $values = [];
        foreach ($call->getRawArgs() as $argument) {
            if ($argument instanceof Arg) {
                $values[] = $argument->value;
            }
        }

        return $values;
    }

    /**
     * The file's top-level statements and every nested statement list: namespace, function,
     * method, closure, property hook and control-flow bodies.
     *
     * @param array<Node> $nodes
     * @return list<list<Stmt>>
     */
    private static function statementLists(array $nodes): array
    {
        $owners = new NodeFinder()->find($nodes, static fn (Node $node): bool => self::statementsOf($node) !== [])
            |> array_values(...);

        return [self::statements($nodes), ...array_map(self::statementsOf(...), $owners)];
    }

    /**
     * Read through `stmts` by name because php-parser declares it on each owner class without a
     * shared interface; a property hook keeps its block in `body`.
     *
     * @return list<Stmt>
     */
    private static function statementsOf(Node $node): array
    {
        $body = match (true) {
            $node instanceof PropertyHook => $node->body,
            property_exists($node, 'stmts') => $node->stmts,
            default => null,
        };

        return is_array($body) ? self::statements($body) : [];
    }

    /**
     * @param array<mixed> $nodes
     * @return list<Stmt>
     */
    private static function statements(array $nodes): array
    {
        return array_filter($nodes, static fn (mixed $node): bool => $node instanceof Stmt) |> array_values(...);
    }

    private static function error(Stmt $statement): IdentifierRuleError
    {
        return RuleErrorBuilder::message(self::MESSAGE)
            ->identifier(self::IDENTIFIER)
            ->line($statement->getStartLine())
            ->tip(self::TIP)
            ->build();
    }
}
