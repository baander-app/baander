<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\InvalidPayload;

use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload as Payload;

final class Controller
{
    public function bare(#[MapRequestPayload] object $payload): void
    {
    }

    public function alias(#[Payload] object $payload): void
    {
    }

    public function fullyQualified(#[\Symfony\Component\HttpKernel\Attribute\MapRequestPayload] object $payload): void
    {
    }

    public function nullable(#[Payload] ?object $payload): void
    {
    }

    public function missing(#[MapRequestPayload] $payload): void
    {
    }

    public function aliasMissing(#[Payload] $payload): void
    {
    }

    public function fullyQualifiedMissing(#[\Symfony\Component\HttpKernel\Attribute\MapRequestPayload] $payload): void
    {
    }

    public function nullableUnion(#[Payload] object|null $payload): void
    {
    }

    public function mixedCaseAttribute(#[\symfony\component\httpkernel\attribute\maprequestpayload] object $payload): void
    {
    }
}
