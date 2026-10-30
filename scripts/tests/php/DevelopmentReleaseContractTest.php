<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\ReleaseContract;
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
