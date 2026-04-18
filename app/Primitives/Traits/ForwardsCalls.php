<?php

namespace App\Primitives\Traits;

trait ForwardsCalls
{
    protected function forwardCallTo(object $object, string $method, array $parameters): mixed
    {
        return $object->{$method}(...$parameters);
    }

    protected function throwBadMethodCallException(string $method): never
    {
        throw new \BadMethodCallException(
            sprintf('Method %s::%s() does not exist.', static::class, $method),
        );
    }
}
