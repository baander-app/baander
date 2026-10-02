<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Fixtures\ValidPayload;

use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload as Payload;

final readonly class RequestDto
{
    public function __construct(public string $name)
    {
    }
}

final class Controller
{
    public function concrete(#[MapRequestPayload] RequestDto $payload): void
    {
    }

    public function alias(#[Payload] RequestDto $payload): void
    {
    }

    public function fullyQualified(#[\Symfony\Component\HttpKernel\Attribute\MapRequestPayload] RequestDto $payload): void
    {
    }

    public function nullable(#[Payload] ?RequestDto $payload): void
    {
    }

    /** @param list<RequestDto> $payload */
    public function typedArray(#[Payload(type: RequestDto::class)] array $payload): void
    {
    }

    public function ordinaryObject(object $payload): void
    {
    }

    public function ordinaryUntyped($payload): void
    {
    }

    public function nullableUnion(#[Payload] RequestDto|null $payload): void
    {
    }
}
