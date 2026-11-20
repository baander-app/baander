<?php

declare(strict_types=1);

namespace Baander\Deployment\Tests;

use Baander\Deployment\Database\Pooler;
use PHPUnit\Framework\TestCase;

final class PoolerHealthcheckTest extends TestCase
{
    public function testReadinessProbeUsesTheConfiguredApplicationIdentity(): void
    {
        $environment = Pooler::environment(Pooler::defaultPools(), 'preview_owner', 'preview_library', 'database');
        $service = Pooler::service('database-image', $environment, 'credentials', 'postgres');

        self::assertSame(
            ['CMD', 'pg_isready', '-h', '127.0.0.1', '-p', '6432', '-U', 'preview_owner', '-d', 'preview_library'],
            $service['healthcheck']['test'],
        );
    }
}
