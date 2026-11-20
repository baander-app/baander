<?php

declare(strict_types=1);

namespace Baander\DevEnvironment\Tests;

use Baander\DevEnvironment\State\ReleaseContract;
use PHPUnit\Framework\TestCase;

final class DevelopmentReleaseContractTest extends TestCase
{
    public function testUnsupportedContractCannotActivate(): void
    {
        $this->expectException(\RuntimeException::class);
        ReleaseContract::fromArray(['version' => 42]);
    }

    public function testLegacyRecordNeedsConversionBeforeActivation(): void
    {
        $this->expectException(\RuntimeException::class);
        ReleaseContract::fromArray(['revision' => 'saved', 'app_image' => 'sha256:saved']);
    }
}
