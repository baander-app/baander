<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Rules;

use Baander\PHPStan\Rules\AssignmentChainPipeOperatorRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<AssignmentChainPipeOperatorRule> */
final class AssignmentChainPipeOperatorRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Chain of assignments can be replaced with a pipe operator';
    private const string TIP = 'See docs-book/part-2-developer-guide/development/coding-conventions.md#pipe-operator for the house fix.';

    protected function getRule(): Rule
    {
        return new AssignmentChainPipeOperatorRule();
    }

    public function testReportsChainsOfThreeToTenAssignmentsOnceAtTheFirst(): void
    {
        $this->analyse(
            [__DIR__ . '/data/assignment-chain-pipe-operator.php'],
            self::errorsAt([13, 27, 36, 53, 86, 97, 108, 119, 135]),
        );
    }

    public function testReportsTopLevelScriptCode(): void
    {
        $this->analyse([__DIR__ . '/data/assignment-chain-pipe-operator-script.php'], self::errorsAt([5]));
    }

    public function testClosuresAndFirstClassCallablesDoNotStopAChain(): void
    {
        $this->analyse(
            [__DIR__ . '/data/assignment-chain-pipe-operator-closure-and-first-class-callable.php'],
            self::errorsAt([13, 22, 34, 47]),
        );
    }

    public function testDocblockEndsTheChainAndStartsANewOne(): void
    {
        $this->analyse([__DIR__ . '/data/assignment-chain-pipe-operator-docblock.php'], self::errorsAt([23]));
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
