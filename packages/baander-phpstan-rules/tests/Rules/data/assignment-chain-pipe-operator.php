<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\AssignmentChainPipeOperator;

use Closure;

final class Chains
{
    public string $title {
        get {
            $title = trim($this->raw);
            $title = strtolower($title);
            $title = ucfirst($title);

            return $title;
        }
    }

    public function __construct(private string $raw = '')
    {
    }

    public function threeAssignments(string $raw): string
    {
        $s = trim($raw);
        $s = strtolower($s);
        $s = ucfirst($s);

        return $s;
    }

    public function methodAndStaticSteps(string $raw): string
    {
        $s = trim($raw);
        $s = $this->fold($s);
        $s = self::squash($s);

        return $s;
    }

    public function twoAssignments(string $raw): string
    {
        $s = trim($raw);
        $s = strtolower($s);

        return $s;
    }

    public function tenAssignments(string $raw): string
    {
        $s = trim($raw);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);

        return $s;
    }

    public function elevenAssignments(string $raw): string
    {
        $s = trim($raw);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);
        $s = strrev($s);

        return $s;
    }

    public function lineCommentsInsideTheChain(string $raw): string
    {
        $s = trim($raw);
        // Lower the case before the first letter is raised.
        $s = strtolower($s);
        # Raise the first letter.
        $s = ucfirst($s);

        return $s;
    }

    public function blockCommentsInsideTheChain(string $raw): string
    {
        $s = trim($raw);
        /* Lower the case before the first letter is raised. */
        $s = strtolower($s);
        /* Raise the first letter. */
        $s = ucfirst($s);

        return $s;
    }

    public function differentVariableEndsTheChain(string $raw): string
    {
        $s = trim($raw);
        $s = strtolower($s);
        $s = ucfirst($s);
        $t = strrev($s);

        return $t;
    }

    public function chainInAClosure(): Closure
    {
        return static function (string $raw): string {
            $s = trim($raw);
            $s = strtolower($s);
            $s = ucfirst($s);

            return $s;
        };
    }

    /**
     * @param list<string> $rows
     * @return list<string>
     */
    public function chainInALoopBody(array $rows): array
    {
        $out = [];
        foreach ($rows as $raw) {
            $s = trim($raw);
            $s = strtolower($s);
            $s = ucfirst($s);
            $out[] = $s;
        }

        return $out;
    }

    public function compoundAssignmentFirst(string $s): string
    {
        $s .= '-suffix';
        $s = trim($s);
        $s = strtolower($s);
        $s = ucfirst($s);

        return $s;
    }

    public function firstValueTakesTheVariable(string $s): string
    {
        $s = trim($s);
        $s = strtolower($s);
        $s = ucfirst($s);

        return $s;
    }

    public function firstValueWithACallArgument(string $raw): string
    {
        $s = trim(strtolower($raw));
        $s = ucfirst($s);
        $s = strrev($s);
        $s = str_pad($s, 8);

        return $s;
    }

    public function literalContinuationAbandonsTheChain(string $raw): string
    {
        $s = trim($raw);
        $s = strtolower($s);
        $s = ucfirst($s);
        $s = 'literal';

        return $s;
    }

    public function continuationWithoutTheVariableAbandonsTheChain(string $raw, string $other): string
    {
        $s = trim($raw);
        $s = strtolower($s);
        $s = ucfirst($s);
        $s = strrev($other);

        return $s;
    }

    public function continuationWithACallArgumentAbandonsTheChain(string $raw): string
    {
        $s = trim($raw);
        $s = strtolower($s);
        $s = ucfirst($s);
        $s = str_pad($s, strlen($raw));

        return $s;
    }

    public function compoundContinuationsKeepTheChain(string $raw): string
    {
        $s = trim($raw);
        $s .= strtoupper($s);
        $s .= strtolower($s);

        return $s;
    }

    private function fold(string $s): string
    {
        return $s;
    }

    private static function squash(string $s): string
    {
        return $s;
    }
}
