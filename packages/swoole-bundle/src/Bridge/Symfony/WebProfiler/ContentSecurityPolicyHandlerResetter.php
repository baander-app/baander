<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\WebProfiler;

use Assert\Assertion;
use ReflectionProperty;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Resetter;
use Symfony\Bundle\WebProfilerBundle\Csp\ContentSecurityPolicyHandler;

final class ContentSecurityPolicyHandlerResetter implements Resetter
{
    private ?ReflectionProperty $cspDisabledProperty = null;

    public function reset(object $service): void
    {
        Assertion::isInstanceOf($service, ContentSecurityPolicyHandler::class);

        $this->cspDisabledProperty()->setValue($service, false);
    }

    private function cspDisabledProperty(): ReflectionProperty
    {
        return $this->cspDisabledProperty ??= new ReflectionProperty(
            ContentSecurityPolicyHandler::class,
            'cspDisabled',
        );
    }
}
