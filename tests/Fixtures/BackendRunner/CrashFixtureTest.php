<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\BackendRunner;

use PHPUnit\Framework\TestCase;

final class CrashFixtureTest extends TestCase
{
    public function testWorkerTerminates(): void
    {
        exit(37);
    }
}
