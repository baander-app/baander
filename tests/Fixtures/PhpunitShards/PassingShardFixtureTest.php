<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\PhpunitShards;

use PHPUnit\Framework\TestCase;

/** Run only by PhpunitShardRunnerTest through a temporary configuration; no phpunit.xml.dist suite includes it. */
final class PassingShardFixtureTest extends TestCase
{
    public function testPasses(): void
    {
        $this->addToAssertionCount(1);
    }
}
