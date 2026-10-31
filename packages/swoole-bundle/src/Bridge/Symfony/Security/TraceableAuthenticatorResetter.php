<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Security;

use Assert\Assertion;
use ReflectionProperty;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Resetter;
use Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator;

final class TraceableAuthenticatorResetter implements Resetter
{
    private const array INITIAL_STATE = [
        'supports' => false,
        'passport' => null,
        'duration' => null,
        'authenticated' => null,
        'exception' => null,
    ];

    /** @var array<string, ReflectionProperty> */
    private array $properties = [];

    public function reset(object $service): void
    {
        Assertion::isInstanceOf($service, TraceableAuthenticator::class);

        // Symfony exposes no reset API for the per-request trace; keep its wrapped authenticator and stub.
        foreach (self::INITIAL_STATE as $name => $value) {
            $property = $this->properties[$name] ??= new ReflectionProperty(TraceableAuthenticator::class, $name);
            $property->setValue($service, $value);
        }
    }
}
