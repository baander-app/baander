<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\BackendRunner;

use PHPUnit\Framework\TestCase;

final class SkippedFixtureTest extends TestCase
{
    public function testSkip(): void
    {
        self::markTestSkipped('Deliberate runner fixture skip.');
    }
}
