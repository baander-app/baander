<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Rules;

use Baander\PHPStan\Rules\NestedCallPipeOperatorRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NestedCallPipeOperatorRule> */
final class NestedCallPipeOperatorRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Nested function calls can be replaced with a pipe operator';
    private const string TIP = 'See docs-book/part-2-developer-guide/development/coding-conventions.md#pipe-operator for the house fix.';

    protected function getRule(): Rule
    {
        return new NestedCallPipeOperatorRule();
    }

    public function testReportsChainsOfThreeToTenLevelsOnceOnTheOutermostCall(): void
    {
        $this->analyse([__DIR__ . '/data/nested-call-pipe-operator.php'], self::errorsAt([21, 26, 34, 39, 44, 49, 54, 59, 64]));
    }

    public function testIgnoresChainsUnderAnAttributeArgumentList(): void
    {
        $this->analyse([__DIR__ . '/data/nested-call-pipe-operator-attribute.php'], []);
    }

    public function testReportsTopLevelScriptCode(): void
    {
        $this->analyse([__DIR__ . '/data/nested-call-pipe-operator-script.php'], self::errorsAt([7]));
    }

    public function testReportsTemplateCodeBetweenInlineHtml(): void
    {
        $this->analyse([__DIR__ . '/data/nested-call-pipe-operator-template.php'], self::errorsAt([6]));
    }

    public function testExitAndDieAreNotCallLevels(): void
    {
        $this->analyse([__DIR__ . '/data/nested-call-pipe-operator-exit.php'], self::errorsAt([12]));
    }

    public function testClosureArgumentsDoNotStopAWalk(): void
    {
        $this->analyse([__DIR__ . '/data/nested-call-pipe-operator-closure-argument.php'], self::errorsAt([12, 18, 24, 32]));
    }

    public function testFirstClassCallablesAreNotCalls(): void
    {
        $this->analyse([__DIR__ . '/data/nested-call-pipe-operator-first-class-callable.php'], self::errorsAt([12, 23, 28]));
    }

    public function testNamedArgumentsContinueTheChain(): void
    {
        $this->analyse([__DIR__ . '/data/nested-call-pipe-operator-named-argument.php'], self::errorsAt([11]));
    }

    public function testSpreadArgumentsContinueTheChain(): void
    {
        $this->analyse([__DIR__ . '/data/nested-call-pipe-operator-spread-argument.php'], self::errorsAt([15]));
    }

    public function testVariableCallablesAreFunctionCalls(): void
    {
        $this->analyse([__DIR__ . '/data/nested-call-pipe-operator-variable-callable.php'], self::errorsAt([13, 18]));
    }

    /**
     * @param list<int> $lines
     * @return list<array{string, int, string}>
     */
    private static function errorsAt(array $lines): array
    {
        return array_map(static fn (int $line): array => [self::MESSAGE, $line, self::TIP], $lines);
    }
}
