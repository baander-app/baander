<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Security;

use Assert\Assertion;
use ReflectionProperty;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Resetter;
use Symfony\Component\Security\Http\Firewall\ContextListener;

final class ContextListenerResetter implements Resetter
{
    private ?ReflectionProperty $registeredProperty = null;

    public function reset(object $service): void
    {
        Assertion::isInstanceOf($service, ContextListener::class);

        $this->registeredProperty()->setValue($service, false);
    }

    private function registeredProperty(): ReflectionProperty
    {
        return $this->registeredProperty ??= new ReflectionProperty(ContextListener::class, 'registered');
    }
}
