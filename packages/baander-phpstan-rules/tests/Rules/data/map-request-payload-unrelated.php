<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\UnrelatedPayload;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class MapRequestPayload
{
}

final class Controller
{
    public function local(#[MapRequestPayload] object $payload): void
    {
    }

    public function localMissing(#[MapRequestPayload] $payload): void
    {
    }

    public function fullyQualified(#[\Baander\PHPStan\Tests\Fixtures\UnrelatedPayload\MapRequestPayload] object $payload): void
    {
    }
}
