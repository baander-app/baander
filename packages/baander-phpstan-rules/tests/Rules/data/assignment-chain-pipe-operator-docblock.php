<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\AssignmentChainPipeOperator;

final class Docblocks
{
    public function docblockEndsTheChain(string $raw): string
    {
        $s = trim($raw);
        $s = strtolower($s);
        /** @var non-empty-string $s */
        $s = ucfirst($s);

        return $s;
    }

    public function docblockStartsAChain(string $raw): string
    {
        $s = '';
        /** @var string $s */
        $s = trim($raw);
        $s = strtolower($s);
        $s = ucfirst($s);

        return $s;
    }
}
