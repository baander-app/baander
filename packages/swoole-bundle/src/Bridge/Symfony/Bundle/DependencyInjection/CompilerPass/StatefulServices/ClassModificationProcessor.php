<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Reflection\ClassModifier;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ClassModificationProcessor
{
    /**
     * @var array<string, true>
     */
    private array $processedClasses = [];

    public function __construct(private readonly ContainerBuilder $container)
    {
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_FINAL_CLASSES, []);
    }

    /**
     * @param class-string $className
     */
    public function processFinalClass(string $className): void
    {
        if (isset($this->processedClasses[$className])) {
            return;
        }

        $this->processedClasses[$className] = true;
        if (ClassModifier::removeFinalFlagsFromClass($className)) {
            /** @var list<class-string> $classes */
            $classes = $this->container->getParameter(ContainerConstants::PARAM_COROUTINES_FINAL_CLASSES);
            $classes[] = $className;
            $this->container->setParameter(ContainerConstants::PARAM_COROUTINES_FINAL_CLASSES, $classes);
        }
    }
}
